<?php

namespace App\Notifications;

use App\Models\StageRun;
use App\Models\WorkflowRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowNeedsAttention extends Notification
{
    use Queueable;

    public function __construct(
        public readonly WorkflowRun $run,
        public readonly StageRun $attempt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->run->loadMissing(['project', 'stageRuns']);
        $this->attempt->loadMissing('stage');
        $projectName = $this->run->project?->name ?? $this->run->github_repository;
        $runLabel = 'RUN-'.str_pad((string) $this->run->getKey(), 4, '0', STR_PAD_LEFT);
        $pullRequest = $this->run->stageRuns
            ->whereNotNull('github_pull_request_url')
            ->sortByDesc('attempt_number')
            ->first();

        $message = (new MailMessage)
            ->subject("Orc action required: {$this->attempt->stage->name} · {$projectName}")
            ->greeting("{$this->attempt->stage->name} needs your attention")
            ->line("{$runLabel} for {$this->run->github_repository}#{$this->run->github_issue_number} is waiting for a human decision.")
            ->line($this->attentionReason())
            ->action('Review workflow in Orc', route('workflows.show', $this->run))
            ->line("GitHub issue: [{$this->run->github_repository}#{$this->run->github_issue_number}]({$this->run->github_issue_url})");

        if ($pullRequest?->github_pull_request_url) {
            $message->line("Pull request: [#{$pullRequest->github_pull_request_number}]({$pullRequest->github_pull_request_url})");
        }

        return $message->line('Orc will not take the human action automatically.');
    }

    private function attentionReason(): string
    {
        return match ($this->attempt->stage->key) {
            'human_review' => 'Review the GitHub issue, pull request, reports, and checks, then approve or request changes in Orc.',
            'development_blocked' => 'Development reported a blocker. Inspect its GitHub report before retrying or cancelling.',
            'qa_blocked' => 'Independent QA could not reach a trustworthy verdict. Inspect its GitHub report before retrying or cancelling.',
            'merge_blocked' => 'The Merge agent could not verify a safe, policy-compliant merge. Inspect its GitHub report before retrying or intervening.',
            default => 'This workflow has entered a human-controlled stage and will not advance without your action.',
        };
    }
}
