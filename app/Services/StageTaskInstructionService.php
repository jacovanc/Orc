<?php

namespace App\Services;

use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Domain\Workflow\StageType;
use App\Models\Project;
use App\Models\ProjectStageInstructionVersion;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\DB;

class StageTaskInstructionService
{
    public const MAX_BODY_LENGTH = 12000;

    /** @return array<string, array{label: string, body: string, boundary: string, version?: int}> */
    public function modes(): array
    {
        return [
            'real_development' => [
                'label' => 'Development',
                'body' => <<<'TEXT'
Read the GitHub issue, discussion, linked pull request when present, reviews, inline comments, prior QA findings, and CI afresh. Inspect the repository, implement only the outstanding issue work, and run appropriate tests. Push the bound attempt branch, create or update its one pull request without merging it, and publish a substantive Development report.
TEXT,
                'boundary' => 'May edit, test, commit, and push only the bound issue branch. Must not merge or claim QA approval.',
            ],
            'real_qa' => [
                'label' => 'Independent QA',
                'body' => <<<'TEXT'
Read the original issue and acceptance criteria, exact pull request, reviews, inline comments, discussion, Development reports, changed files, commits, checks, and CI afresh. Independently inspect the implementation and run checks appropriate to every acceptance criterion. Publish one substantive QA report on the exact pull request with an honest pass, fail, or blocked verdict.
TEXT,
                'boundary' => 'Read-only review and testing. Must not edit implementation, commit, push, merge, approve, or close GitHub items.',
            ],
            'real_explanation' => [
                'label' => 'Explanation',
                'version' => 2,
                'body' => <<<'TEXT'
Read the issue, exact pull request and code, reviews, inline comments, recent human discussion, prior reports, and checks afresh. Identify the unanswered human questions; the latest Human Review entry time is only a hint, not a hard cutoff. Treat each unanswered inline pull-request review comment as its own conversation: reply directly in that comment's existing review thread with a concrete answer and relevant code or test citations so its file and line context remain visible. Use GitHub's native pull-review-comment reply endpoint (`POST /repos/{owner}/{repo}/pulls/{pull_number}/comments/{comment_id}/replies`) rather than a general pull-request comment for each inline answer. Do not combine inline answers into one general pull-request comment. For a question that exists only in a top-level review body or pull-request conversation and has no inline reply target, post one focused response that links to that source question. After answering, publish only a short completion report on the exact pull request that links to the individual answers without repeating their text. If no clear question exists, publish an honest clarification-needed report instead of inventing one.
TEXT,
                'boundary' => 'Read-only explanation. Must not edit, commit, push, merge, approve, request changes, or start Development.',
            ],
            'real_merge' => [
                'label' => 'Merge',
                'body' => <<<'TEXT'
Read the issue, exact QA-approved pull request head, reviews, inline comments, QA reports, checks, mergeability, base, and repository policy afresh. Merge a clean pull request without bypassing protections. Resolve and test only clearly mechanical behavior-preserving conflicts; a material conflict must follow Orc's bounded fresh QA and Human Review path. Publish a substantive Merge report with the verified result.
TEXT,
                'boundary' => 'May merge only the exact bound QA-approved pull request under repository policy and Orc’s conflict rules.',
            ],
        ];
    }

    /** @return array{agent_mode: string, label: string, body: string, version: int, source: string, boundary: string} */
    public function current(Project $project, string $mode): array
    {
        $definition = $this->modes()[$mode] ?? null;
        if (! $definition) {
            throw new WorkflowConflict('That agent stage has no editable task body.');
        }
        $configured = ProjectStageInstructionVersion::query()
            ->whereBelongsTo($project)
            ->where('agent_mode', $mode)
            ->latest('version')
            ->first();

        return [
            'agent_mode' => $mode,
            'label' => $definition['label'],
            'body' => $configured?->body ?? $definition['body'],
            'version' => $configured?->version ?? ($definition['version'] ?? 1),
            'source' => $configured ? 'project' : 'default',
            'boundary' => $definition['boundary'],
        ];
    }

    public function update(Project $project, User $actor, string $mode, string $body): ProjectStageInstructionVersion
    {
        if ($project->user_id !== $actor->id || ! $actor->can_trigger_amp) {
            throw new WorkflowConflict('You are not authorized to edit this project’s agent instructions.');
        }
        if (! isset($this->modes()[$mode])) {
            throw new WorkflowConflict('That agent stage has no editable task body.');
        }

        return DB::transaction(function () use ($project, $actor, $mode, $body) {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($locked->user_id !== $actor->id || ! $actor->can_trigger_amp) {
                throw new WorkflowConflict('You are not authorized to edit this project’s agent instructions.');
            }
            $configuredVersion = (int) ProjectStageInstructionVersion::query()
                ->where('project_id', $locked->id)
                ->where('agent_mode', $mode)
                ->max('version');
            $defaultVersion = $this->modes()[$mode]['version'] ?? 1;
            $version = max($configuredVersion, $defaultVersion) + 1;

            return ProjectStageInstructionVersion::query()->create([
                'project_id' => $locked->id,
                'created_by' => $actor->id,
                'agent_mode' => $mode,
                'version' => $version,
                'body' => $body,
            ]);
        }, 3);
    }

    public function snapshotForRun(WorkflowRun $run, WorkflowDefinition $definition): void
    {
        $definition->loadMissing('stages');
        $modes = $definition->stages
            ->where('type', StageType::Agent)
            ->map(fn ($stage) => $stage->config['agent_mode'] ?? null)
            ->filter(fn ($mode) => is_string($mode) && isset($this->modes()[$mode]))
            ->unique();

        foreach ($modes as $mode) {
            $current = $this->current($run->project, $mode);
            $run->stageInstructions()->create([
                'agent_mode' => $mode,
                'source_version' => $current['version'],
                'source' => $current['source'],
                'body' => $current['body'],
            ]);
        }
    }
}
