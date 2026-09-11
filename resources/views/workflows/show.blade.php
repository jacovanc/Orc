<x-app-layout>
    <x-slot:title>{{ $run->github_repository }} #{{ $run->github_issue_number }}</x-slot:title>

    @php
        $active = $run->activeStageRun;
        $outcomes = $active?->stage?->outgoingTransitions ?? collect();
        $ampEnabled = (bool) config('services.amp.enabled');
        $activeLaunch = $active?->ampLaunch;
        $activeMode = $active?->stage?->config['agent_mode'] ?? null;
        $controlAttempt = $active ?: (
            in_array($run->status, [
                \App\Domain\Workflow\WorkflowStatus::Paused,
                \App\Domain\Workflow\WorkflowStatus::Failed,
            ], true)
                ? $run->stageRuns->sortByDesc('attempt_number')->first()
                : null
        );
        $manualDestinations = $run->definition->stages
            ->reject(fn ($stage) => $stage->type === \App\Domain\Workflow\StageType::Terminal)
            ->sortBy('position');
        $manualEntryEvent = $active
            ? $run->events->first(fn ($event) =>
                $event->stage_run_id === $active->id
                && $event->type === 'stage.started'
                && ($event->metadata['source'] ?? null) === 'manual_override'
            )
            : null;
        $awaitingController = $ampEnabled
            && $active?->stage?->type === \App\Domain\Workflow\StageType::Agent
            && $activeLaunch?->launch_status === \App\Domain\Workflow\AmpLaunchStatus::Pending
            && ! $active?->amp_thread_id;
        $latestPublication = $run->stageRuns
            ->whereNotNull('github_pull_request_url')
            ->sortByDesc('attempt_number')
            ->first();
        $latestReportEvent = $run->events
            ->filter(fn ($event) => isset($event->metadata['github_report_url']))
            ->sortByDesc('id')
            ->first();
        $latestPassingQa = $active
            ? $run->stageRuns
                ->where('attempt_number', '<', $active->attempt_number)
                ->where('outcome', 'pass')
                ->whereNotNull('github_pull_request_head_sha')
                ->sortByDesc('attempt_number')
                ->first(fn ($attempt) => ($attempt->stage->config['agent_mode'] ?? null) === 'real_qa')
            : null;
        $mergeReviewCycles = $run->stageRuns
            ->where('outcome', 'requires_review')
            ->filter(fn ($attempt) => ($attempt->stage->config['agent_mode'] ?? null) === 'real_merge')
            ->count();
    @endphp

    <div>
        <a href="{{ $run->project ? route('projects.show', $run->project) : route('workflows.index') }}" class="inline-flex items-center gap-2 text-sm text-zinc-600 transition hover:text-zinc-300"><span>←</span> {{ $run->project?->name ?? 'Workflows' }}</a>
        <div class="mt-6 flex flex-wrap items-center gap-3">
            <span class="status-pill status-{{ $run->displayStatusClass() }}">{{ $run->displayStatus() }}</span>
            <span class="font-mono text-xs text-zinc-700">RUN-{{ str_pad($run->id, 4, '0', STR_PAD_LEFT) }}</span>
        </div>
        <h1 class="mt-4 text-3xl font-semibold tracking-[-0.04em] text-white sm:text-4xl">{{ $run->github_repository }} <span class="text-zinc-600">#{{ $run->github_issue_number }}</span></h1>
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <a href="{{ $run->github_issue_url }}" target="_blank" rel="noopener" class="button-quiet border border-white/[0.08] bg-white/[0.03]">Open issue <span>↗</span></a>
            @if ($latestPublication?->github_pull_request_url && (! $active || $active->stage->type === \App\Domain\Workflow\StageType::Agent))
                <a href="{{ $latestPublication->github_pull_request_url }}" target="_blank" rel="noopener" class="button-quiet border border-sky-300/15 bg-sky-300/[0.05] text-sky-200">Open PR #{{ $latestPublication->github_pull_request_number }} <span>↗</span></a>
            @endif
            <span class="text-xs text-zinc-600">{{ $run->definition->name }} v{{ $run->definition->version }} · {{ $run->started_at->diffForHumans() }}</span>
        </div>
    </div>

    <div class="flex flex-col">
    <section class="panel order-2 mt-8 px-4 py-5 sm:px-7 sm:py-6" data-workflow-state-map>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <div class="eyebrow"><span></span> Workflow map</div>
                <h2 class="mt-2 text-lg font-semibold text-white">States and possible transitions</h2>
            </div>
            <p class="hidden max-w-md text-xs leading-5 text-zinc-500 sm:block">Each state shows where its outcomes lead, including retries and review loops.</p>
        </div>
        <div class="mt-5 grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-3 lg:grid-cols-4">
            @foreach ($run->definition->stages as $stage)
                @php
                    $isCurrent = $run->current_stage_id === $stage->id;
                    $latestStageAttempt = $run->stageRuns
                        ->where('workflow_stage_id', $stage->id)
                        ->sortByDesc('attempt_number')
                        ->first();
                    $wasVisited = (bool) $latestStageAttempt;
                    $stateClass = $isCurrent
                        ? 'border-orange-400/40 bg-orange-400/[0.09] shadow-[0_0_24px_rgba(249,115,22,.1)]'
                        : match ($latestStageAttempt?->outcome) {
                            'fail' => 'border-red-400/20 bg-red-400/[0.05]',
                            'blocked' => 'border-amber-300/20 bg-amber-300/[0.05]',
                            default => $wasVisited
                                ? 'border-emerald-400/15 bg-emerald-400/[0.04]'
                                : 'border-white/[0.07] bg-black/10',
                        };
                    $stageTransitions = $run->definition->transitions->where('from_stage_id', $stage->id);
                @endphp
                <article class="rounded-2xl border p-3 sm:p-4 {{ $stateClass }}" data-workflow-state="{{ $stage->key }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold leading-5 sm:text-sm {{ $isCurrent ? 'text-orange-100' : 'text-zinc-200' }}">{{ $stage->name }}</p>
                            <p class="mt-1 font-mono text-[8px] uppercase tracking-[0.14em] text-zinc-500 sm:text-[9px]">{{ $stage->type->value }} state</p>
                        </div>
                        @if ($isCurrent)
                            <span class="shrink-0 rounded-full bg-orange-400/15 px-2 py-1 font-mono text-[8px] uppercase tracking-wider text-orange-300">Current</span>
                        @elseif ($wasVisited)
                            <span class="shrink-0 text-sm {{ in_array($latestStageAttempt?->outcome, ['fail', 'blocked'], true) ? 'text-amber-300' : 'text-emerald-400' }}">{{ in_array($latestStageAttempt?->outcome, ['fail', 'blocked'], true) ? '!' : '✓' }}</span>
                        @endif
                    </div>
                    <div class="mt-4 space-y-1.5 border-t border-white/[0.06] pt-3">
                        @forelse ($stageTransitions as $transition)
                            <div class="text-[10px] leading-4 sm:text-[11px]">
                                <span class="text-zinc-400">{{ str($transition->outcome)->replace('_', ' ')->title() }}</span>
                                <span class="text-zinc-600"> → </span><span class="text-zinc-300">{{ $transition->toStage->name }}</span>
                            </div>
                        @empty
                            <p class="text-[11px] text-zinc-700">Final state</p>
                        @endforelse
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    @if ($active)
        <section class="order-1 mt-6 overflow-hidden rounded-3xl border border-orange-400/20 bg-gradient-to-br from-orange-400/[0.09] to-ink-900/80 shadow-2xl shadow-orange-950/10">
            <div class="grid lg:grid-cols-[.7fr_1.3fr]">
                <div class="border-b border-orange-400/10 p-6 sm:p-8 lg:border-b-0 lg:border-r">
                    <div class="eyebrow"><span></span> Active attempt {{ str_pad($active->attempt_number, 2, '0', STR_PAD_LEFT) }}</div>
                    <h2 class="mt-5 text-2xl font-semibold tracking-tight text-white">{{ $active->stage->name }}</h2>
                    <p class="mt-3 text-sm leading-6 text-zinc-400">
                        @if ($active->stage->type === \App\Domain\Workflow\StageType::Agent)
                            @if (! $ampEnabled) Amp execution is disabled; use simulation to test the transition.
                            @elseif ($awaitingController) Waiting for the project controller to acknowledge this launch. No agent is running yet.
                            @elseif ($activeMode === 'real_development') A fresh Amp agent is implementing the GitHub issue.
                            @elseif ($activeMode === 'real_qa') A fresh independent agent is inspecting and testing the bound pull request.
                            @elseif ($activeMode === 'real_explanation') A fresh read-only agent is answering Human Review questions on the bound pull request.
                            @elseif ($activeMode === 'real_merge') A fresh agent is verifying and merging the QA-approved pull request under repository policy.
                            @else A fresh agent is running a harmless integration proof; this is not code validation or approval.
                            @endif
                        @else
                            Review the GitHub evidence, then choose the next state.
                        @endif
                    </p>
                    <div class="mt-5 flex flex-wrap items-center gap-2">
                        <span class="status-pill status-{{ $awaitingController ? 'pending' : $active->status->value }}">{{ $awaitingController ? 'waiting for controller' : $active->status->value }}</span>
                        @if ($activeMode === 'real_development')
                            <span class="font-mono text-[10px] uppercase tracking-wider text-sky-200">Development agent</span>
                        @elseif ($activeMode === 'real_qa')
                            <span class="font-mono text-[10px] uppercase tracking-wider text-violet-200">Substantive QA</span>
                        @elseif ($activeMode === 'real_explanation')
                            <span class="font-mono text-[10px] uppercase tracking-wider text-teal-200">Read-only explanation</span>
                        @elseif ($activeMode === 'real_merge')
                            <span class="font-mono text-[10px] uppercase tracking-wider text-amber-200">Policy-bound merge</span>
                        @elseif ($activeMode)
                            <span class="font-mono text-[10px] uppercase tracking-wider text-amber-200">Integration proof</span>
                        @endif
                    </div>
                </div>

                <div class="p-6 sm:p-8">
                    @if ($active->stage->type === \App\Domain\Workflow\StageType::Agent)
                        @if ($ampEnabled)
                            <div class="rounded-2xl border border-white/[0.08] bg-black/15 p-5">
                                <div class="flex flex-wrap gap-3">
                                    @if ($active->amp_thread_id)
                                    <a class="button-primary" href="https://ampcode.com/threads/{{ $active->amp_thread_id }}" target="_blank" rel="noopener">
                                        Open Amp thread <span>↗</span>
                                    </a>
                                    @endif
                                    @if ($latestPublication?->github_pull_request_url)
                                        <a class="button-quiet border border-sky-300/15 text-sky-200" href="{{ $latestPublication->github_pull_request_url }}" target="_blank" rel="noopener">Open PR #{{ $latestPublication->github_pull_request_number }} <span>↗</span></a>
                                    @endif
                                </div>
                                @if ($activeLaunch?->delivery_status === \App\Domain\Workflow\AmpDeliveryStatus::Delivered && $activeLaunch?->launch_status === \App\Domain\Workflow\AmpLaunchStatus::Pending)
                                    <div class="mt-5 rounded-xl border border-sky-300/20 bg-sky-300/[0.06] px-4 py-3 text-xs leading-5 text-sky-100/70">
                                        Amp accepted this event into its queue, but the controller has not acknowledged it. HTTP 202 does not mean an Orb was launched. Check the dedicated controller lifecycle in <a class="text-sky-200 underline decoration-sky-300/30 underline-offset-2" href="{{ route('projects.settings', $run->project) }}">Project settings</a>.
                                    </div>
                                @endif
                                @if ($activeMode === 'real_merge' && $latestPassingQa?->github_pull_request_head_sha)
                                    <div class="mt-4 rounded-xl border border-amber-300/15 bg-amber-300/[0.05] px-4 py-3 text-xs leading-5 text-amber-100/70">
                                        QA-approved head <span class="font-mono text-amber-200">{{ substr($latestPassingQa->github_pull_request_head_sha, 0, 12) }}</span>. Material conflict review cycle: {{ $mergeReviewCycles }}/1. Clean or mechanical resolutions may merge; the first material resolution returns to QA and Human Review.
                                    </div>
                                @endif
                                @if ($activeLaunch?->delivery_status === \App\Domain\Workflow\AmpDeliveryStatus::Ambiguous || $activeLaunch?->launch_status === \App\Domain\Workflow\AmpLaunchStatus::Ambiguous)
                                    <div class="mt-5 rounded-xl border border-amber-300/20 bg-amber-300/[0.07] px-4 py-3 text-xs leading-5 text-amber-100/70">
                                        Delivery has an ambiguous network outcome. Orc will not launch a duplicate; cancel this run or reconcile it from the Amp callback history.
                                    </div>
                                @endif
                                <details class="mt-5 border-t border-white/[0.07] pt-4 text-xs text-zinc-500">
                                    <summary class="cursor-pointer select-none font-medium text-zinc-400 hover:text-white">Amp dispatch details</summary>
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        <span class="status-pill status-{{ $activeLaunch?->delivery_status?->value ?? 'waiting' }}">Delivery {{ $activeLaunch?->delivery_status?->value ?? 'preparing' }}</span>
                                        <span class="status-pill status-{{ $activeLaunch?->launch_status?->value ?? 'waiting' }}">Launch {{ $activeLaunch?->launch_status?->value ?? 'pending' }}</span>
                                        <span class="font-mono text-[10px] text-zinc-700">Command {{ $activeLaunch ? '#'.$activeLaunch->id : 'preparing' }}</span>
                                    </div>
                                    @unless ($active->amp_thread_id)
                                        <p class="mt-3">The thread link appears after Amp acknowledges the launch.</p>
                                    @endunless
                                </details>
                            </div>
                        @else
                            <div class="mb-5 rounded-xl border border-amber-300/15 bg-amber-300/[0.06] px-4 py-3 text-xs leading-5 text-amber-100/70">
                                <strong class="text-amber-200">Simulation only.</strong> No Amp thread is created and nothing is published to GitHub.
                            </div>
                            <p class="field-label">Record a simulated outcome</p>
                            <div class="flex flex-wrap gap-3">
                                @foreach ($outcomes as $transition)
                                    <form method="POST" action="{{ route('workflows.attempts.simulate', [$run, $active]) }}">
                                        @csrf
                                        <input type="hidden" name="outcome" value="{{ $transition->outcome }}">
                                        <button class="{{ in_array($transition->outcome, ['fail'], true) ? 'button-danger' : 'button-primary' }}" type="submit">
                                            {{ str($transition->outcome)->replace('_', ' ')->title() }}
                                            <span class="opacity-60">→ {{ $transition->toStage->name }}</span>
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        @endif
                    @else
                        @if ($latestPublication?->github_pull_request_url || $latestReportEvent)
                            <div class="mb-4 rounded-2xl border border-orange-300/20 bg-orange-300/[0.07] p-5" data-review-evidence>
                                <p class="font-mono text-[10px] uppercase tracking-[0.14em] text-orange-300">Review on GitHub</p>
                                @if ($latestPublication?->github_pull_request_url)
                                    <a class="mt-3 flex items-center justify-between gap-4 rounded-xl bg-orange-500 px-4 py-3 font-semibold text-white shadow-lg shadow-orange-950/30 transition hover:bg-orange-400" href="{{ $latestPublication->github_pull_request_url }}" target="_blank" rel="noopener">
                                        <span>Open pull request #{{ $latestPublication->github_pull_request_number }}</span>
                                        <span aria-hidden="true">↗</span>
                                    </a>
                                @endif
                                @if ($latestReportEvent)
                                    <a class="mt-3 inline-flex items-center gap-2 rounded-lg border border-orange-200/15 px-3 py-2 text-xs font-medium text-orange-100/80 transition hover:border-orange-200/30 hover:text-white" href="{{ $latestReportEvent->metadata['github_report_url'] }}" target="_blank" rel="noopener">Open latest agent report <span>↗</span></a>
                                @endif
                            </div>
                        @endif
                        @if ($active->stage->key === 'human_review' && $manualEntryEvent)
                            <div class="mb-4 rounded-xl border border-sky-300/20 bg-sky-300/[0.07] px-4 py-3 text-xs leading-5 text-sky-100/80">
                                <strong class="text-sky-200">Manual review entry.</strong> You moved this workflow here directly. No QA result is implied; inspect GitHub before making the human release decision.
                            </div>
                        @elseif ($run->definition->version === 2 && $active->stage->key === 'human_review')
                            <div class="mb-4 rounded-xl border border-amber-300/20 bg-amber-300/[0.07] px-4 py-3 text-xs leading-5 text-amber-100/80">
                                <strong class="text-amber-200">Human release gate.</strong> QA was an integration proof only; no independent substantive code validation has occurred yet.
                            </div>
                        @elseif ($run->definition->version >= 5 && $active->stage->key === 'human_review')
                            <div class="mb-4 rounded-xl border border-violet-300/20 bg-violet-300/[0.07] px-4 py-3 text-xs leading-5 text-violet-100/80">
                                <strong class="text-violet-200">Human release gate.</strong> Ask questions starts a fresh read-only Explanation agent after you post questions on the PR. Request Changes starts fresh Development. Approval starts Merge; none of these actions is automatic approval.
                            </div>
                        @elseif ($run->definition->version >= 4 && $active->stage->key === 'human_review')
                            <div class="mb-4 rounded-xl border border-violet-300/20 bg-violet-300/[0.07] px-4 py-3 text-xs leading-5 text-violet-100/80">
                                <strong class="text-violet-200">Substantive QA passed.</strong> Approval starts a fresh policy-bound Merge agent; this click does not itself merge. Request Changes returns to fresh Development reading GitHub feedback.
                            </div>
                        @elseif ($run->definition->version >= 3 && $active->stage->key === 'human_review')
                            <div class="mb-4 rounded-xl border border-violet-300/20 bg-violet-300/[0.07] px-4 py-3 text-xs leading-5 text-violet-100/80">
                                <strong class="text-violet-200">Substantive QA passed.</strong> This is still a separate human release decision; Orc never approves or merges automatically.
                            </div>
                        @elseif ($active->stage->key === 'qa_blocked')
                            <div class="mb-4 rounded-xl border border-amber-300/20 bg-amber-300/[0.07] px-4 py-3 text-xs leading-5 text-amber-100/80">
                                <strong class="text-amber-200">Operator intervention required.</strong> QA could not reach a trustworthy verdict. Inspect its GitHub report before retrying in a fresh thread and Orb or cancelling the run.
                            </div>
                        @elseif ($active->stage->key === 'merge_blocked')
                            <div class="mb-4 rounded-xl border border-amber-300/20 bg-amber-300/[0.07] px-4 py-3 text-xs leading-5 text-amber-100/80">
                                <strong class="text-amber-200">No merge was completed.</strong> The Merge agent could not verify a safe policy-compliant merge. Inspect its PR report, address the repository/access/conflict blocker, then retry in a fresh Orb. A second material-conflict review loop is intentionally not automatic.
                            </div>
                        @endif
                        @foreach ($outcomes as $transition)
                            @if ($transition->outcome === 'request_changes')
                                <form method="POST" action="{{ route('workflows.attempts.human-action', [$run, $active]) }}" class="rounded-2xl border border-white/[0.08] bg-black/10 p-5">
                                    @csrf
                                    <input type="hidden" name="outcome" value="request_changes">
                                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                                        <div><p class="text-sm font-medium text-orange-100">Send back to Development</p><p class="mt-1 text-xs leading-5 text-zinc-400">Comment on GitHub first. The fresh Development agent will reread the bound pull request.</p></div>
                                        <button class="button-quiet shrink-0 border border-white/15 bg-white/[0.05] text-zinc-200 hover:bg-white/[0.09]" type="submit">Request changes <span class="text-zinc-500">→</span></button>
                                    </div>
                                </form>
                            @elseif ($transition->outcome === 'ask_questions')
                                <form method="POST" action="{{ route('workflows.attempts.human-action', [$run, $active]) }}" class="mt-4 rounded-2xl border border-teal-300/15 bg-teal-300/[0.05] p-5">
                                    @csrf
                                    <input type="hidden" name="outcome" value="ask_questions">
                                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                                        <div><p class="text-sm font-medium text-teal-100">Ask about the implementation</p><p class="mt-1 text-xs leading-5 text-teal-200/60">Post your questions on the PR first. A fresh read-only agent answers there, then returns here without changing code or approval state.</p></div>
                                        <button class="button-quiet shrink-0 border border-teal-200/15 bg-teal-300/[0.06] text-teal-100 hover:bg-teal-300/[0.1]" type="submit">Ask questions <span class="text-teal-300/50">→</span></button>
                                    </div>
                                </form>
                            @elseif ($transition->outcome === 'approve')
                                <form method="POST" action="{{ route('workflows.attempts.human-action', [$run, $active]) }}" class="mt-4 flex flex-col justify-between gap-4 rounded-2xl border border-emerald-400/15 bg-emerald-400/[0.05] p-5 sm:flex-row sm:items-center">
                                    @csrf
                                    <input type="hidden" name="outcome" value="approve">
                                    <div><p class="text-sm font-medium text-emerald-100">Ready to ship</p><p class="mt-1 text-xs text-emerald-200/50">{{ $transition->toStage->key === 'merge' ? 'Approve and start a fresh Merge agent.' : 'Approve and complete this workflow.' }}</p></div>
                                    <button class="button-primary w-full whitespace-nowrap bg-emerald-500 hover:bg-emerald-400 sm:w-auto" type="submit">{{ $transition->toStage->key === 'merge' ? 'Approve for merge' : 'Approve' }}</button>
                                </form>
                            @elseif ($transition->outcome === 'retry')
                                <form method="POST" action="{{ route('workflows.attempts.human-action', [$run, $active]) }}" class="rounded-2xl border border-sky-300/15 bg-sky-300/[0.05] p-5">
                                    @csrf
                                    <input type="hidden" name="outcome" value="retry">
                                    <p class="text-sm font-medium text-sky-100">Retry {{ $transition->toStage->key === 'development' ? 'Development' : $transition->toStage->name }}</p>
                                    <p class="mt-1 text-xs leading-5 text-sky-200/60">Starts a new numbered attempt in a fresh thread and Orb. The agent rereads GitHub from scratch.</p>
                                    <button class="button-primary mt-4 bg-sky-500 hover:bg-sky-400" type="submit">Retry <span class="opacity-60">→ {{ $transition->toStage->key === 'development' ? 'Development' : $transition->toStage->name }}</span></button>
                                </form>
                            @endif
                        @endforeach
                    @endif
                </div>
            </div>
        </section>
    @elseif ($run->status === \App\Domain\Workflow\WorkflowStatus::Completed)
        <section class="order-1 mt-6 rounded-3xl border border-emerald-400/20 bg-emerald-400/[0.07] p-7 sm:p-9">
            <p class="font-mono text-xs uppercase tracking-[0.16em] text-emerald-400">Workflow complete</p>
            @php
                $verifiedMerge = $run->stageRuns->whereNotNull('github_merge_commit_sha')->sortByDesc('attempt_number')->first();
            @endphp
            <h2 class="mt-3 text-2xl font-semibold text-white">{{ $verifiedMerge ? 'Verified merged and marked Done' : 'Approved and marked Done' }}</h2>
            <p class="mt-2 text-sm text-zinc-500">The complete attempt history and append-only event timeline remain below.</p>
            @if ($verifiedMerge)
                <a class="mt-4 inline-flex items-center gap-2 font-mono text-xs text-emerald-300 hover:text-emerald-200" href="https://github.com/{{ $run->github_repository }}/commit/{{ $verifiedMerge->github_merge_commit_sha }}" target="_blank" rel="noopener">Merge commit {{ substr($verifiedMerge->github_merge_commit_sha, 0, 12) }} ↗</a>
            @endif
        </section>
    @elseif ($run->status === \App\Domain\Workflow\WorkflowStatus::Failed)
        <section class="order-1 mt-6 rounded-3xl border border-red-400/20 bg-red-400/[0.06] p-7 sm:p-9">
            <p class="font-mono text-xs uppercase tracking-[0.16em] text-red-300">Workflow failed</p>
            <h2 class="mt-3 text-2xl font-semibold text-white">Agent launch or execution could not complete safely</h2>
            <p class="mt-2 text-sm text-zinc-400">No transition was taken. Inspect the immutable attempt and event history below.</p>
        </section>
    @elseif ($run->status === \App\Domain\Workflow\WorkflowStatus::Paused)
        <section class="order-1 mt-6 rounded-3xl border border-sky-300/20 bg-sky-300/[0.06] p-7 sm:p-9">
            <p class="font-mono text-xs uppercase tracking-[0.16em] text-sky-200">Workflow paused</p>
            <h2 class="mt-3 text-2xl font-semibold text-white">The previous attempt was stopped safely</h2>
            <p class="mt-2 text-sm text-zinc-500">Choose any actionable stage below to resume with a new numbered attempt. Existing attempts and events remain unchanged.</p>
        </section>
    @endif
    </div>

    @if ($controlAttempt && in_array($run->status, [
        \App\Domain\Workflow\WorkflowStatus::Running,
        \App\Domain\Workflow\WorkflowStatus::Paused,
        \App\Domain\Workflow\WorkflowStatus::Failed,
    ], true))
        <details class="panel group mt-6 overflow-hidden" data-recovery-controls>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5 marker:hidden sm:px-7">
                <div>
                    <h2 class="font-semibold text-white">Recovery controls</h2>
                    <p class="mt-1 text-xs text-zinc-600">Stop, redirect, or cancel this workflow.</p>
                </div>
                <span class="text-zinc-600 transition group-open:rotate-45 group-open:text-orange-300" aria-hidden="true">＋</span>
            </summary>
            <div class="grid gap-0 border-t border-white/[0.07] lg:grid-cols-[.8fr_1.2fr]">
                <div class="border-b border-white/[0.07] p-6 sm:p-7 lg:border-b-0 lg:border-r">
                    <p class="text-sm leading-6 text-zinc-400">Manual controls create a new audited attempt; completed attempts are never rewritten.</p>

                    @if ($run->status === \App\Domain\Workflow\WorkflowStatus::Running && $controlAttempt->stage->type === \App\Domain\Workflow\StageType::Agent)
                        <form class="mt-5" method="POST" action="{{ route('workflows.attempts.pause', [$run, $controlAttempt]) }}" onsubmit="return confirm('Stop this agent attempt and pause the workflow?')">
                            @csrf
                            <button class="button-danger" type="submit">Stop current agent</button>
                            <p class="mt-2 text-xs leading-5 text-zinc-600">Sends a cancellation command to the bound Amp thread when one exists. The workflow remains resumable.</p>
                        </form>
                    @endif
                    @if (in_array($run->status, [\App\Domain\Workflow\WorkflowStatus::Running, \App\Domain\Workflow\WorkflowStatus::Paused], true))
                        <form class="mt-4" method="POST" action="{{ route('workflows.cancel', $run) }}" onsubmit="return confirm('Cancel this workflow? This cannot be resumed.')">
                            @csrf
                            <button class="button-danger" type="submit">Cancel entire workflow</button>
                        </form>
                    @endif
                </div>
                <div class="p-6 sm:p-7">
                    <form method="POST" action="{{ route('workflows.attempts.override-stage', [$run, $controlAttempt]) }}" onsubmit="return confirm('Move this workflow to the selected stage? This creates a new audited attempt.')">
                        @csrf
                        <label class="field-label" for="target_stage_id">Move to stage</label>
                        <select class="field-input" id="target_stage_id" name="target_stage_id" required>
                            <option value="" disabled @selected(! old('target_stage_id'))>Choose a stage…</option>
                            @foreach ($manualDestinations as $destination)
                                <option value="{{ $destination->id }}" @selected((int) old('target_stage_id') === $destination->id)>
                                    {{ $destination->name }} · {{ str($destination->type->value)->title() }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-2 text-xs leading-5 text-zinc-400">Agent stages start a fresh thread and Orb and may spend tokens. Human Review waits for your decision. In workflows v4+, approval starts Merge rather than finishing directly.</p>
                        <button class="button-primary mt-5" type="submit">
                            {{ $run->status === \App\Domain\Workflow\WorkflowStatus::Running ? 'Stop & move' : 'Resume at stage' }}
                        </button>
                    </form>
                </div>
            </div>
        </details>
    @endif

    <details class="panel group mt-8 overflow-hidden" data-workflow-history>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5 marker:hidden sm:px-7">
            <div><h2 class="font-semibold text-white">History &amp; audit</h2><p class="mt-1 text-xs text-zinc-600">{{ $run->stageRuns->count() }} attempts · {{ $run->events->count() }} events</p></div>
            <span class="text-zinc-600 transition group-open:rotate-45 group-open:text-orange-300" aria-hidden="true">＋</span>
        </summary>
        <div class="grid gap-0 border-t border-white/[0.07] xl:grid-cols-[1.25fr_.75fr]">
            <section class="border-b border-white/[0.07] xl:border-b-0 xl:border-r">
                <div class="border-b border-white/[0.07] px-6 py-4"><h3 class="text-sm font-semibold text-white">Stage attempts</h3></div>
                <div class="divide-y divide-white/[0.05]">
                    @foreach ($run->stageRuns as $attempt)
                        @php
                            $reportEvent = $run->events->first(fn ($event) => $event->stage_run_id === $attempt->id && isset($event->metadata['github_report_url']));
                            $attemptMode = $attempt->stage->config['agent_mode'] ?? null;
                            $executedInstruction = $attemptMode ? $run->stageInstructions->firstWhere('agent_mode', $attemptMode) : null;
                            $attemptAwaitingController = $ampEnabled
                                && $attempt->id === $active?->id
                                && $attempt->stage->type === \App\Domain\Workflow\StageType::Agent
                                && $attempt->ampLaunch?->launch_status === \App\Domain\Workflow\AmpLaunchStatus::Pending
                                && ! $attempt->amp_thread_id;
                        @endphp
                        <article class="px-6 py-4 text-sm">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="font-mono text-xs text-zinc-600">#{{ str_pad($attempt->attempt_number, 2, '0', STR_PAD_LEFT) }}</span>
                                    <p class="font-medium text-zinc-200">{{ $attempt->stage->name }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="status-pill status-{{ $attemptAwaitingController ? 'pending' : $attempt->status->value }}">{{ $attemptAwaitingController ? 'waiting' : $attempt->status->value }}</span>
                                    @if ($attempt->outcome)<span class="text-xs text-zinc-500">{{ str($attempt->outcome)->replace('_', ' ')->title() }}</span>@endif
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs">
                                @if ($attempt->amp_thread_id)
                                    <a class="font-mono text-orange-300 hover:text-orange-200" href="https://ampcode.com/threads/{{ $attempt->amp_thread_id }}" target="_blank" rel="noopener">Thread ↗</a>
                                @endif
                                @if ($reportEvent)
                                    <a class="text-zinc-400 hover:text-white" href="{{ $reportEvent->metadata['github_report_url'] }}" target="_blank" rel="noopener">Report ↗</a>
                                @endif
                                @if ($attempt->github_pull_request_url)
                                    <a class="text-sky-300 hover:text-sky-200" href="{{ $attempt->github_pull_request_url }}" target="_blank" rel="noopener">PR #{{ $attempt->github_pull_request_number }} ↗</a>
                                @endif
                                @if ($attempt->github_branch)
                                    <a class="text-zinc-500 hover:text-zinc-300" href="https://github.com/{{ $run->github_repository }}/tree/{{ rawurlencode($attempt->github_branch) }}" target="_blank" rel="noopener">Branch ↗</a>
                                @endif
                                @if ($attempt->github_merge_commit_sha)
                                    <a class="text-emerald-300 hover:text-emerald-200" href="https://github.com/{{ $run->github_repository }}/commit/{{ $attempt->github_merge_commit_sha }}" target="_blank" rel="noopener">Merge {{ substr($attempt->github_merge_commit_sha, 0, 10) }} ↗</a>
                                @endif
                                @unless ($attempt->amp_thread_id || $reportEvent || $attempt->github_pull_request_url || $attempt->github_branch || $attempt->github_merge_commit_sha)
                                    <span class="font-mono text-[10px] text-zinc-700">{{ $ampEnabled ? ($attempt->ampLaunch?->launch_status?->value ?? 'No external reference') : 'Not connected' }}</span>
                                @endunless
                            </div>
                            @if ($executedInstruction)
                                <details class="mt-3 rounded-xl border border-white/[0.06] bg-black/10 px-3 py-2 text-xs text-zinc-500">
                                    <summary class="cursor-pointer select-none hover:text-zinc-300">Run task body snapshot v{{ $executedInstruction->source_version }}</summary>
                                    <pre class="mt-3 whitespace-pre-wrap break-words font-mono text-[11px] leading-5 text-zinc-400">{{ $executedInstruction->body }}</pre>
                                </details>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>

            <section>
                <div class="border-b border-white/[0.07] px-6 py-4"><h3 class="text-sm font-semibold text-white">Event timeline</h3></div>
                <ol class="px-6 py-5">
                @foreach ($run->events->reverse() as $event)
                    @php
                        $eventDotClass = match ($event->type) {
                            'workflow.completed' => 'bg-emerald-400',
                            'workflow.cancelled' => 'bg-zinc-400',
                            'workflow.paused' => 'bg-sky-300',
                            'workflow.stage_overridden' => 'bg-sky-300',
                            default => 'bg-orange-400',
                        };
                    @endphp
                    <li class="relative border-l border-white/[0.08] pb-6 pl-5 last:border-transparent last:pb-0">
                        <span class="absolute -left-1 top-1 h-2 w-2 rounded-full border-2 border-ink-900 {{ $eventDotClass }}"></span>
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-sm font-medium text-zinc-300">{{ $event->type === 'stage.started' ? 'Stage Activated' : str($event->type)->replace(['.', '_'], ' ')->title() }}</p>
                            <time class="shrink-0 font-mono text-[9px] text-zinc-700">{{ $event->happened_at->format('H:i:s') }}</time>
                        </div>
                        @if ($event->metadata)
                            <p class="mt-1 text-xs leading-5 text-zinc-600">
                                @if (isset($event->metadata['stage_key'])) {{ str($event->metadata['stage_key'])->replace('_', ' ')->title() }} @endif
                                @if (isset($event->metadata['attempt_number'])) · attempt {{ $event->metadata['attempt_number'] }} @endif
                                @if (isset($event->metadata['outcome'])) · {{ str($event->metadata['outcome'])->replace('_', ' ') }} @endif
                                @if (isset($event->metadata['source']) && $event->metadata['source'] === 'agent_simulation') · simulated @endif
                                @if ($event->type === 'workflow.stage_overridden' && isset($event->metadata['from_stage_key'], $event->metadata['to_stage_key']))
                                    {{ str($event->metadata['from_stage_key'])->replace('_', ' ')->title() }} attempt {{ $event->metadata['from_attempt_number'] ?? '—' }}
                                    → {{ str($event->metadata['to_stage_key'])->replace('_', ' ')->title() }} attempt {{ $event->metadata['to_attempt_number'] ?? '—' }}
                                @endif
                                @if (($event->metadata['source'] ?? null) === 'manual_override') · manual move @endif
                            </p>
                            @if (isset($event->metadata['github_report_url']))
                                <a class="mt-2 inline-flex text-xs text-orange-300 hover:text-orange-200" href="{{ $event->metadata['github_report_url'] }}" target="_blank" rel="noopener">Open GitHub report ↗</a>
                            @endif
                            @if (isset($event->metadata['amp_thread_id']))
                                <a class="mt-2 ml-3 inline-flex text-xs text-zinc-400 hover:text-white" href="https://ampcode.com/threads/{{ $event->metadata['amp_thread_id'] }}" target="_blank" rel="noopener">Open Amp thread ↗</a>
                            @endif
                        @endif
                    </li>
                @endforeach
                </ol>
            </section>
        </div>
    </details>
</x-app-layout>
