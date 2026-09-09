<?php

namespace App\Jobs;

use App\Domain\Workflow\AmpDeliveryStatus;
use App\Domain\Workflow\AmpLaunchStatus;
use App\Models\AmpLaunch;
use App\Services\AmpConnectionUrlGuard;
use App\Services\AmpLaunchPayload;
use App\Services\AmpProjectConnectionService;
use App\Services\AmpSignature;
use App\Services\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DeliverAmpLaunch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $ampLaunchId)
    {
        $this->onQueue('amp-launches');
    }

    public function backoff(): array
    {
        return [5, 15, 60];
    }

    public function uniqueId(): string
    {
        return (string) $this->ampLaunchId;
    }

    public function handle(
        AmpLaunchPayload $payloadFactory,
        AmpSignature $signature,
        WorkflowEngine $engine,
        AmpConnectionUrlGuard $urlGuard,
        ?AmpProjectConnectionService $connections = null,
    ): void {
        $connections ??= app(AmpProjectConnectionService::class);
        $launch = DB::transaction(function () {
            $locked = AmpLaunch::query()->lockForUpdate()->findOrFail($this->ampLaunchId);

            if (in_array($locked->delivery_status, [
                AmpDeliveryStatus::Delivered,
                AmpDeliveryStatus::Failed,
                AmpDeliveryStatus::Ambiguous,
            ], true)) {
                return null;
            }

            if ($locked->launch_status !== AmpLaunchStatus::Pending) {
                if (in_array($locked->launch_status, [
                    AmpLaunchStatus::Launched,
                    AmpLaunchStatus::Completed,
                    AmpLaunchStatus::Failed,
                ], true)) {
                    $locked->forceFill([
                        'delivery_status' => AmpDeliveryStatus::Delivered,
                        'last_error_code' => null,
                        'last_error_message' => null,
                    ])->save();

                    return null;
                }
            }

            $locked->forceFill([
                'delivery_status' => AmpDeliveryStatus::Delivering,
                'delivery_attempts' => $locked->delivery_attempts + 1,
            ])->save();

            return $locked->fresh();
        }, 3);

        if (! $launch) {
            return;
        }

        $launch->loadMissing('ampProjectConnection');
        $url = (string) $launch->ampProjectConnection?->launch_webhook_url;
        $secret = (string) $launch->ampProjectConnection?->launch_signing_secret;
        if ($url === '' || $secret === '') {
            $engine->failAmpLaunchDelivery($launch, 'configuration', 'The bound Amp project connection is incomplete.');

            return;
        }
        try {
            $urlGuard->assertAllowed($url);
        } catch (Throwable) {
            $engine->failAmpLaunchDelivery($launch, 'unsafe_controller_url', 'The bound controller URL is not permitted.');

            return;
        }

        $body = $launch->payload_body;
        if (! $body) {
            $body = $payloadFactory->body($launch);
            $launch->forceFill([
                'payload_body' => $body,
                'payload_hash' => hash('sha256', $body),
            ])->save();
        }
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->withHeaders([
                    'Idempotency-Key' => $launch->idempotency_key,
                    'X-Orc-Event-Id' => $launch->event_id,
                    'X-Orc-Timestamp' => (string) $timestamp,
                    'X-Orc-Signature' => $signature->sign($body, $launch->event_id, $timestamp, $secret),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $exception) {
            $this->recordTransientFailure($launch, 'network', 'The bound Amp controller could not be reached.');

            throw new RuntimeException('Transient Amp controller delivery failure.');
        }

        if ($response->successful()) {
            $this->markDelivered($launch, $response->status());

            return;
        }

        if ($response->status() === 408 || $response->status() === 429 || $response->serverError()) {
            $this->recordTransientFailure($launch, 'http_'.$response->status(), 'Retryable Amp webhook response.');
            throw new RuntimeException('Retryable Amp controller response.');
        }

        if (in_array($response->status(), [404, 410], true)) {
            if (! $connections->markWebhookUnavailable($launch->ampProjectConnection, $url)) {
                $this->recordTransientFailure($launch, 'controller_url_rotated', 'The controller webhook changed during delivery.');
                throw new RuntimeException('Retrying delivery against the refreshed Amp controller webhook.');
            }
            $engine->failAmpLaunchDelivery(
                $launch,
                'http_'.$response->status(),
                'The bound Amp controller webhook is unavailable; the cause is unknown. No Orb was launched. Restore the shown controller thread if archived and resume its trigger if separately paused, then reverify that exact connection. A replacement connection will not move this historical run.',
                $response->status(),
            );

            return;
        }

        $engine->failAmpLaunchDelivery(
            $launch,
            'http_'.$response->status(),
            'Amp webhook permanently rejected the launch request.',
            $response->status(),
        );
    }

    public function failed(?Throwable $exception): void
    {
        $launch = AmpLaunch::query()->find($this->ampLaunchId);
        if (! $launch) {
            return;
        }

        app(WorkflowEngine::class)->markAmpLaunchAmbiguous(
            $launch,
            'retry_exhausted',
            'Amp launch delivery exhausted its retry budget.',
        );
    }

    private function markDelivered(AmpLaunch $launch, int $status): void
    {
        DB::transaction(function () use ($launch, $status) {
            $locked = AmpLaunch::query()->lockForUpdate()->findOrFail($launch->getKey());
            if ($locked->delivery_status === AmpDeliveryStatus::Failed) {
                return;
            }

            $locked->forceFill([
                'delivery_status' => AmpDeliveryStatus::Delivered,
                'last_http_status' => $status,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();
        }, 3);
    }

    private function recordTransientFailure(AmpLaunch $launch, string $code, string $message): void
    {
        AmpLaunch::query()->whereKey($launch->getKey())->update([
            'last_error_code' => $code,
            'last_error_message' => mb_substr($message, 0, 1000),
            'updated_at' => now(),
        ]);
    }
}
