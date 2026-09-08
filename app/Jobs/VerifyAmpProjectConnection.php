<?php

namespace App\Jobs;

use App\Models\AmpProjectConnection;
use App\Services\AmpConnectionUrlGuard;
use App\Services\AmpProjectConnectionService;
use App\Services\AmpSignature;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class VerifyAmpProjectConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $connectionId)
    {
        $this->onQueue('amp-launches');
    }

    public function uniqueId(): string
    {
        return 'verify:'.$this->connectionId;
    }

    public function backoff(): array
    {
        return [5, 15, 60];
    }

    public function handle(AmpSignature $signature, AmpConnectionUrlGuard $guard, AmpProjectConnectionService $service): void
    {
        $connection = AmpProjectConnection::query()->findOrFail($this->connectionId);
        if ($connection->status !== 'verifying') {
            return;
        }
        $guard->assertAllowed((string) $connection->launch_webhook_url);
        $body = (string) $connection->verification_payload;
        if ($body === '' || ! $connection->verification_event_id) {
            $service->markDeliveryFailed($connection, 'configuration', 'Connection verification payload is incomplete.');

            return;
        }
        $connection->forceFill(['verification_attempts' => $connection->verification_attempts + 1])->save();
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->withHeaders([
                    'Idempotency-Key' => $connection->verification_event_id,
                    'X-Orc-Event-Id' => $connection->verification_event_id,
                    'X-Orc-Timestamp' => (string) $timestamp,
                    'X-Orc-Signature' => $signature->sign(
                        $body,
                        $connection->verification_event_id,
                        $timestamp,
                        (string) $connection->launch_signing_secret,
                    ),
                ])
                ->withBody($body, 'application/json')
                ->post((string) $connection->launch_webhook_url);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Transient Amp connection verification delivery failure.');
        }
        if ($response->successful()) {
            return;
        }
        if ($response->status() === 408 || $response->status() === 429 || $response->serverError()) {
            throw new RuntimeException('Retryable Amp connection verification response.');
        }
        $service->markDeliveryFailed($connection, 'controller_rejected', 'The selected Amp controller rejected verification.');
    }

    public function failed(?Throwable $exception): void
    {
        $connection = AmpProjectConnection::query()->find($this->connectionId);
        if ($connection) {
            app(AmpProjectConnectionService::class)->markDeliveryFailed(
                $connection,
                'verification_delivery_failed',
                'Connection verification could not be delivered safely.',
            );
        }
    }
}
