<?php

namespace App\Jobs;

use App\Models\AmpLaunch;
use App\Services\AmpConnectionUrlGuard;
use App\Services\AmpSignature;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DeliverAmpCancellation implements ShouldBeUnique, ShouldQueue
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
        return 'cancel:'.$this->ampLaunchId;
    }

    public function handle(AmpSignature $signature, AmpConnectionUrlGuard $urlGuard): void
    {
        $launch = DB::transaction(function () {
            $locked = AmpLaunch::query()
                ->with('stageRun.workflowRun')
                ->lockForUpdate()
                ->findOrFail($this->ampLaunchId);

            if (in_array($locked->cancellation_status, ['delivered', 'failed'], true)) {
                return null;
            }
            if (! $locked->cancellation_event_id || ! $locked->stageRun->amp_thread_id) {
                return null;
            }

            $locked->forceFill([
                'cancellation_status' => 'delivering',
                'cancellation_attempts' => $locked->cancellation_attempts + 1,
            ])->save();

            return $locked->fresh(['stageRun.workflowRun']);
        }, 3);

        if (! $launch) {
            return;
        }

        $launch->loadMissing('ampProjectConnection');
        $url = (string) $launch->ampProjectConnection?->launch_webhook_url;
        $secret = (string) $launch->ampProjectConnection?->launch_signing_secret;
        if ($url === '' || $secret === '') {
            $this->mark($launch, 'failed', 'The bound Amp project connection is incomplete.');

            return;
        }
        try {
            $urlGuard->assertAllowed($url);
        } catch (Throwable) {
            $this->mark($launch, 'failed', 'The bound controller URL is not permitted.');

            return;
        }

        $body = json_encode([
            'schema_version' => 1,
            'command' => 'cancel',
            'event_id' => $launch->cancellation_event_id,
            'idempotency_key' => $launch->cancellation_event_id,
            'launch_event_id' => $launch->event_id,
            'stage_run_id' => $launch->stage_run_id,
            'thread_id' => $launch->stageRun->amp_thread_id,
            'connection_id' => $launch->ampProjectConnection->public_id,
            'amp_project_id' => $launch->ampProjectConnection->amp_project_id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->withHeaders([
                    'Idempotency-Key' => $launch->cancellation_event_id,
                    'X-Orc-Event-Id' => $launch->cancellation_event_id,
                    'X-Orc-Timestamp' => (string) $timestamp,
                    'X-Orc-Signature' => $signature->sign($body, $launch->cancellation_event_id, $timestamp, $secret),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $exception) {
            $this->mark($launch, 'delivering', 'The bound Amp controller could not be reached.');

            throw new RuntimeException('Transient Amp controller cancellation failure.');
        }

        if ($response->successful()) {
            $this->mark($launch, 'delivered', null);

            return;
        }
        if ($response->status() === 408 || $response->status() === 429 || $response->serverError()) {
            $this->mark($launch, 'delivering', 'Retryable Amp cancellation response.');
            throw new RuntimeException('Retryable Amp controller cancellation response.');
        }

        $this->mark($launch, 'failed', 'Amp permanently rejected the cancellation command.');
    }

    public function failed(?Throwable $exception): void
    {
        $launch = AmpLaunch::query()->find($this->ampLaunchId);
        if ($launch && $launch->cancellation_status !== 'delivered') {
            $this->mark($launch, 'ambiguous', 'Cancellation delivery exhausted its retry budget.');
        }
    }

    private function mark(AmpLaunch $launch, string $status, ?string $message): void
    {
        AmpLaunch::query()->whereKey($launch->getKey())->update([
            'cancellation_status' => $status,
            'cancellation_last_error' => $message ? mb_substr($message, 0, 1000) : null,
            'updated_at' => now(),
        ]);
    }
}
