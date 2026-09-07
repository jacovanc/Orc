<?php

namespace App\Jobs;

use App\Domain\Workflow\AmpLaunchStatus;
use App\Domain\Workflow\WorkflowStatus;
use App\Models\AmpLaunch;
use App\Services\AmpSignature;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class ReconcileAmpLaunch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $ampLaunchId, public readonly int $round = 1)
    {
        $this->onQueue('amp-launches');
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public function uniqueId(): string
    {
        return "reconcile:{$this->ampLaunchId}:{$this->round}";
    }

    public function handle(AmpSignature $signature): void
    {
        $launch = $this->activeLaunch();
        if (! $launch) {
            return;
        }

        $url = (string) config('services.amp.launch_webhook_url');
        $secret = (string) config('services.amp.launch_signing_secret');
        if ($url === '' || $secret === '') {
            return;
        }

        $body = $launch->payload_body;
        $timestamp = now()->getTimestamp();
        $reconciliationKey = "{$launch->idempotency_key}:reconcile:{$this->round}";

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->withHeaders([
                    'Idempotency-Key' => $reconciliationKey,
                    'X-Orc-Event-Id' => $launch->event_id,
                    'X-Orc-Timestamp' => (string) $timestamp,
                    'X-Orc-Signature' => $signature->sign($body, $launch->event_id, $timestamp, $secret),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $exception) {
            throw $exception;
        }

        if ($response->status() === 408 || $response->status() === 429 || $response->serverError()) {
            throw new RequestException($response);
        }

        if ($response->successful()) {
            $this->scheduleNextRound();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->scheduleNextRound();
    }

    private function activeLaunch(): ?AmpLaunch
    {
        $launch = AmpLaunch::query()
            ->with('stageRun.workflowRun')
            ->find($this->ampLaunchId);

        if (
            ! $launch
            || ! $launch->payload_body
            || ! $launch->stageRun->amp_thread_id
            || $launch->stageRun->active_slot !== 1
            || $launch->stageRun->workflowRun->status !== WorkflowStatus::Running
            || in_array($launch->launch_status, [AmpLaunchStatus::Completed, AmpLaunchStatus::Failed], true)
        ) {
            return null;
        }

        return $launch;
    }

    private function scheduleNextRound(): void
    {
        if ($this->round >= 12 || ! $this->activeLaunch()) {
            return;
        }

        self::dispatch($this->ampLaunchId, $this->round + 1)
            ->delay(now()->addMinutes(5));
    }
}
