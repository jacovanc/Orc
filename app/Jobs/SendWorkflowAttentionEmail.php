<?php

namespace App\Jobs;

use App\Domain\Workflow\StageRunStatus;
use App\Models\WorkflowAttentionDelivery;
use App\Notifications\WorkflowNeedsAttention;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SendWorkflowAttentionEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue((string) config('workflow.notifications.queue'));
    }

    public function uniqueId(): string
    {
        return 'workflow-attention:'.$this->deliveryId;
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $delivery = DB::transaction(function () {
            $locked = WorkflowAttentionDelivery::query()
                ->with(['workflowRun.user', 'stageRun.stage'])
                ->lockForUpdate()
                ->find($this->deliveryId);

            if (! $locked) {
                return null;
            }

            if (in_array($locked->status, ['sent', 'skipped'], true)) {
                return null;
            }

            if (
                ! config('workflow.notifications.email_enabled')
                || $locked->stageRun->status !== StageRunStatus::Waiting
                || $locked->stageRun->active_slot !== 1
                || $locked->workflowRun->current_stage_id !== $locked->stageRun->workflow_stage_id
            ) {
                $locked->forceFill([
                    'status' => 'skipped',
                    'last_error' => null,
                    'failed_at' => null,
                ])->save();

                return null;
            }

            $locked->forceFill([
                'status' => 'sending',
                'attempts' => $locked->attempts + 1,
                'last_error' => null,
                'failed_at' => null,
            ])->save();

            return $locked;
        }, 3);

        if (! $delivery) {
            return;
        }

        try {
            $delivery->workflowRun->user->notify(new WorkflowNeedsAttention(
                $delivery->workflowRun,
                $delivery->stageRun,
            ));
        } catch (Throwable) {
            WorkflowAttentionDelivery::query()
                ->whereKey($delivery->getKey())
                ->where('status', 'sending')
                ->update([
                    'status' => 'pending',
                    'last_error' => 'Mail delivery failed and will be retried.',
                    'updated_at' => now(),
                ]);

            throw new RuntimeException('Workflow attention email delivery failed.');
        }

        WorkflowAttentionDelivery::query()
            ->whereKey($delivery->getKey())
            ->where('status', 'sending')
            ->update([
                'status' => 'sent',
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        WorkflowAttentionDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereNotIn('status', ['sent', 'skipped'])
            ->update([
                'status' => 'failed',
                'last_error' => 'Mail delivery failed after all retry attempts.',
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
