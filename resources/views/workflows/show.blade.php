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
        $boundPublication = $active
            ? $run->stageRuns
                ->where('attempt_number', '<', $active->attempt_number)
                ->whereNotNull('github_pull_request_number')
                ->sortByDesc('attempt_number')
                ->first()
            : null;
    @endphp

    <div class="flex flex-col justify-between gap-6 lg:flex-row lg:items-start">
        <div>
            <a href="{{ $run->project ? route('projects.show', $run->project) : route('workflows.index') }}" class="inline-flex items-center gap-2 text-sm text-zinc-600 transition hover:text-zinc-300"><span>←</span> {{ $run->project?->name ?? 'Workflows' }}</a>
            <div class="mt-6 flex flex-wrap items-center gap-3">
                <span class="status-pill status-{{ $run->status->value }}">{{ $run->status->value }}</span>
                <span class="font-mono text-xs text-zinc-700">RUN-{{ str_pad($run->id, 4, '0', STR_PAD_LEFT) }}</span>
            </div>
            <h1 class="mt-4 text-3xl font-semibold tracking-[-0.04em] text-white sm:text-4xl">{{ $run->github_repository }} <span class="text-zinc-600">#{{ $run->github_issue_number }}</span></h1>
            <div class="mt-3 flex flex-wrap items-center gap-4 text-sm text-zinc-500">
                <span>{{ $run->definition->name }} v{{ $run->definition->version }}</span>
                <span class="h-1 w-1 rounded-full bg-zinc-700"></span>
                <span>Started {{ $run->started_at->diffForHumans() }}</span>
                <a href="{{ $run->github_issue_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-zinc-300 transition hover:text-orange-300">Open issue <span>↗</span></a>
            </div>
        </div>
        @if (in_array($run->status, [\App\Domain\Workflow\WorkflowStatus::Running, \App\Domain\Workflow\WorkflowStatus::Paused], true))
            <form method="POST" action="{{ route('workflows.cancel', $run) }}" onsubmit="return confirm('Cancel this workflow? This cannot be resumed.')">
                @csrf
                <button class="button-danger" type="submit">Cancel entire workflow</button>
            </form>
        @endif
    </div>

    <section class="panel mt-10 overflow-hidden px-5 py-6 sm:px-8">
        <div class="flex min-w-[42rem] items-start overflow-x-auto pb-2">
            @foreach ($run->definition->stages as $stage)
                @php
                    $isCurrent = $run->current_stage_id === $stage->id;
                    $latestStageAttempt = $run->stageRuns
                        ->where('workflow_stage_id', $stage->id)
                        ->sortByDesc('attempt_number')
                        ->first();
                    $wasVisited = (bool) $latestStageAttempt;
                    $stepClass = $isCurrent
                        ? 'border-orange-400/50 bg-orange-400/10 text-orange-300 shadow-[0_0_24px_rgba(249,115,22,.12)]'
                        : match ($latestStageAttempt?->outcome) {
                            'fail' => 'border-red-400/25 bg-red-400/[0.07] text-red-300',
                            'blocked' => 'border-amber-300/25 bg-amber-300/[0.07] text-amber-200',
                            default => $wasVisited
                                ? 'border-emerald-400/20 bg-emerald-400/[0.07] text-emerald-400'
                                : 'border-white/[0.08] bg-black/10 text-zinc-700',
                        };
                @endphp
                <div class="flex flex-1 items-start {{ $loop->last ? '' : 'after:mt-5 after:h-px after:min-w-8 after:flex-1 after:bg-white/10' }}">
                    <div class="min-w-[7rem]">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl border text-sm font-semibold {{ $stepClass }}">
                            @if (! $isCurrent && $latestStageAttempt?->outcome === 'fail') ×
                            @elseif (! $isCurrent && $latestStageAttempt?->outcome === 'blocked') !
                            @elseif ($wasVisited && ! $isCurrent) ✓
                            @else {{ str_pad($stage->position, 2, '0', STR_PAD_LEFT) }}
                            @endif
                        </div>
                        <p class="mt-3 text-sm font-medium {{ $isCurrent ? 'text-white' : 'text-zinc-500' }}">{{ $stage->name }}</p>
                        <p class="mt-1 font-mono text-[9px] uppercase tracking-wider text-zinc-700">{{ $stage->type->value }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    @if ($active)
        <section class="mt-6 overflow-hidden rounded-3xl border border-orange-400/20 bg-gradient-to-br from-orange-400/[0.09] to-ink-900/80 shadow-2xl shadow-orange-950/10">
            <div class="grid lg:grid-cols-[1fr_1.15fr]">
                <div class="border-b border-orange-400/10 p-6 sm:p-8 lg:border-b-0 lg:border-r">
                    <div class="eyebrow"><span></span> Active attempt {{ str_pad($active->attempt_number, 2, '0', STR_PAD_LEFT) }}</div>
                    <h2 class="mt-5 text-2xl font-semibold tracking-tight text-white">{{ $active->stage->name }}</h2>
                    <p class="mt-3 text-sm leading-6 text-zinc-400">
                        @if ($active->stage->type === \App\Domain\Workflow\StageType::Agent)
                            @if ($ampEnabled)
                                @if ($awaitingController)
                                    Orc activated this stage and queued its command, but no agent thread or Orb is running until the dedicated controller acknowledges the launch.
                                    @if ($activeMode !== 'real_development' && $activeMode !== 'real_qa')
                                        If acknowledged, this remains integration proof only—not code validation or approval.
                                    @endif
                                @elseif ($activeMode === 'real_development')
                                    A fresh private Amp thread and Orb is implementing the bound issue with normal Amp tools and your existing native repository access. Orc never provisions or copies GitHub credentials.
                                @elseif ($activeMode === 'real_qa')
                                    A fresh private Amp thread and Orb is independently inspecting and testing the exact bound pull request. QA may not change implementation, push, merge, or grant human approval.
                                @else
                                    A fresh private Amp thread and Orb is running an integration proof. It has normal Amp tools but is instructed to make no code changes; its result is not code validation or approval.
                                @endif
                            @else
                                Amp execution is disabled. Use the explicit simulation control to test orchestration locally.
                            @endif
                        @else
                            Review the issue and pull request on GitHub, then choose a permitted outcome here. Orc does not require or store a feedback link or confirmation.
                        @endif
                    </p>
                    <div class="mt-6 flex items-center gap-2">
                        <span class="status-pill status-{{ $awaitingController ? 'pending' : $active->status->value }}">{{ $awaitingController ? 'waiting for controller' : $active->status->value }}</span>
                        <span class="font-mono text-[10px] uppercase tracking-wider text-zinc-600">{{ $active->stage->type->value }} stage</span>
                        @if ($activeMode === 'real_development')
                            <span class="rounded-full border border-sky-300/20 bg-sky-300/[0.08] px-2.5 py-1 font-mono text-[9px] uppercase tracking-wider text-sky-200">Real Development</span>
                        @elseif ($activeMode === 'real_qa')
                            <span class="rounded-full border border-violet-300/20 bg-violet-300/[0.08] px-2.5 py-1 font-mono text-[9px] uppercase tracking-wider text-violet-200">Substantive QA</span>
                        @elseif ($activeMode)
                            <span class="rounded-full border border-amber-300/20 bg-amber-300/[0.08] px-2.5 py-1 font-mono text-[9px] uppercase tracking-wider text-amber-200">Integration proof</span>
                        @endif
                    </div>
                </div>

                <div class="p-6 sm:p-8">
                    @if ($active->stage->type === \App\Domain\Workflow\StageType::Agent)
                        @if ($ampEnabled)
                            <div class="rounded-2xl border border-white/[0.08] bg-black/15 p-5">
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p class="field-label mb-1">Amp dispatch</p>
                                        <p class="text-xs leading-5 text-zinc-600">Durable launch command {{ $activeLaunch ? '#'.$activeLaunch->id : 'preparing' }}</p>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="status-pill status-{{ $activeLaunch?->delivery_status?->value ?? 'waiting' }}">Delivery {{ $activeLaunch?->delivery_status?->value ?? 'preparing' }}</span>
                                        <span class="status-pill status-{{ $activeLaunch?->launch_status?->value ?? 'waiting' }}">Launch {{ $activeLaunch?->launch_status?->value ?? 'pending' }}</span>
                                    </div>
                                </div>
                                @if ($active->amp_thread_id)
                                    <a class="mt-5 inline-flex items-center gap-2 rounded-xl border border-orange-400/20 bg-orange-400/[0.07] px-4 py-3 font-mono text-xs text-orange-200 transition hover:bg-orange-400/[0.12]" href="https://ampcode.com/threads/{{ $active->amp_thread_id }}" target="_blank" rel="noopener">
                                        Open Amp thread <span>↗</span>
                                    </a>
                                @else
                                    <p class="mt-5 text-xs text-zinc-500">The thread link will appear after Amp acknowledges the launch.</p>
                                @endif
                                @if ($activeLaunch?->delivery_status === \App\Domain\Workflow\AmpDeliveryStatus::Delivered && $activeLaunch?->launch_status === \App\Domain\Workflow\AmpLaunchStatus::Pending)
                                    <div class="mt-5 rounded-xl border border-sky-300/20 bg-sky-300/[0.06] px-4 py-3 text-xs leading-5 text-sky-100/70">
                                        Amp accepted this event into its queue, but the controller has not acknowledged it. HTTP 202 does not mean an Orb was launched. Check the dedicated controller lifecycle in <a class="text-sky-200 underline decoration-sky-300/30 underline-offset-2" href="{{ route('projects.settings', $run->project) }}">Project settings</a>.
                                    </div>
                                @endif
                                @if ($activeMode === 'real_qa' && $boundPublication?->github_pull_request_url)
                                    <a class="mt-3 inline-flex items-center gap-2 rounded-xl border border-violet-300/20 bg-violet-300/[0.07] px-4 py-3 font-mono text-xs text-violet-200 transition hover:bg-violet-300/[0.12]" href="{{ $boundPublication->github_pull_request_url }}" target="_blank" rel="noopener">
                                        Inspect bound PR #{{ $boundPublication->github_pull_request_number }} <span>↗</span>
                                    </a>
                                @endif
                                @if ($activeLaunch?->delivery_status === \App\Domain\Workflow\AmpDeliveryStatus::Ambiguous || $activeLaunch?->launch_status === \App\Domain\Workflow\AmpLaunchStatus::Ambiguous)
                                    <div class="mt-5 rounded-xl border border-amber-300/20 bg-amber-300/[0.07] px-4 py-3 text-xs leading-5 text-amber-100/70">
                                        Delivery has an ambiguous network outcome. Orc will not launch a duplicate; cancel this run or reconcile it from the Amp callback history.
                                    </div>
                                @endif
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
                        @if ($active->stage->key === 'human_review' && $manualEntryEvent)
                            <div class="mb-4 rounded-xl border border-sky-300/20 bg-sky-300/[0.07] px-4 py-3 text-xs leading-5 text-sky-100/80">
                                <strong class="text-sky-200">Manual review entry.</strong> You moved this workflow here directly. No QA result is implied; inspect GitHub before making the human release decision.
                            </div>
                        @elseif ($run->definition->version === 2 && $active->stage->key === 'human_review')
                            <div class="mb-4 rounded-xl border border-amber-300/20 bg-amber-300/[0.07] px-4 py-3 text-xs leading-5 text-amber-100/80">
                                <strong class="text-amber-200">Human release gate.</strong> QA was an integration proof only; no independent substantive code validation has occurred yet.
                            </div>
                        @elseif ($run->definition->version >= 3 && $active->stage->key === 'human_review')
                            <div class="mb-4 rounded-xl border border-violet-300/20 bg-violet-300/[0.07] px-4 py-3 text-xs leading-5 text-violet-100/80">
                                <strong class="text-violet-200">Substantive QA passed.</strong> This is still a separate human release decision; Orc never approves or merges automatically.
                            </div>
                        @elseif ($active->stage->key === 'qa_blocked')
                            <div class="mb-4 rounded-xl border border-amber-300/20 bg-amber-300/[0.07] px-4 py-3 text-xs leading-5 text-amber-100/80">
                                <strong class="text-amber-200">Operator intervention required.</strong> QA could not reach a trustworthy verdict. Inspect its GitHub report before retrying in a fresh thread and Orb or cancelling the run.
                            </div>
                        @endif
                        @foreach ($outcomes as $transition)
                            @if ($transition->outcome === 'request_changes')
                                <form method="POST" action="{{ route('workflows.attempts.human-action', [$run, $active]) }}" class="rounded-2xl border border-white/[0.08] bg-black/10 p-5">
                                    @csrf
                                    <input type="hidden" name="outcome" value="request_changes">
                                    <p class="text-sm font-medium text-orange-100">Send back to Development</p>
                                    <p class="mt-1 text-xs leading-5 text-zinc-400">Leave any feedback directly on GitHub. The fresh Development agent will reread the bound pull request, reviews, inline comments, and discussion before editing.</p>
                                    <button class="button-quiet mt-4 border border-white/10" type="submit">Request changes <span class="text-zinc-600">→ Development</span></button>
                                </form>
                            @elseif ($transition->outcome === 'approve')
                                <form method="POST" action="{{ route('workflows.attempts.human-action', [$run, $active]) }}" class="mt-4 flex items-center justify-between gap-4 rounded-2xl border border-emerald-400/15 bg-emerald-400/[0.05] p-5">
                                    @csrf
                                    <input type="hidden" name="outcome" value="approve">
                                    <div><p class="text-sm font-medium text-emerald-100">Ready to ship</p><p class="mt-1 text-xs text-emerald-200/50">Approve and complete this workflow.</p></div>
                                    <button class="button-primary bg-emerald-500 hover:bg-emerald-400" type="submit">Approve</button>
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
        <section class="mt-6 rounded-3xl border border-emerald-400/20 bg-emerald-400/[0.07] p-7 sm:p-9">
            <p class="font-mono text-xs uppercase tracking-[0.16em] text-emerald-400">Workflow complete</p>
            <h2 class="mt-3 text-2xl font-semibold text-white">Approved and marked Done</h2>
            <p class="mt-2 text-sm text-zinc-500">The complete attempt history and append-only event timeline remain below.</p>
        </section>
    @elseif ($run->status === \App\Domain\Workflow\WorkflowStatus::Failed)
        <section class="mt-6 rounded-3xl border border-red-400/20 bg-red-400/[0.06] p-7 sm:p-9">
            <p class="font-mono text-xs uppercase tracking-[0.16em] text-red-300">Workflow failed</p>
            <h2 class="mt-3 text-2xl font-semibold text-white">Agent launch or execution could not complete safely</h2>
            <p class="mt-2 text-sm text-zinc-400">No transition was taken. Inspect the immutable attempt and event history below.</p>
        </section>
    @elseif ($run->status === \App\Domain\Workflow\WorkflowStatus::Paused)
        <section class="mt-6 rounded-3xl border border-sky-300/20 bg-sky-300/[0.06] p-7 sm:p-9">
            <p class="font-mono text-xs uppercase tracking-[0.16em] text-sky-200">Workflow paused</p>
            <h2 class="mt-3 text-2xl font-semibold text-white">The previous attempt was stopped safely</h2>
            <p class="mt-2 text-sm text-zinc-500">Choose any actionable stage below to resume with a new numbered attempt. Existing attempts and events remain unchanged.</p>
        </section>
    @endif

    @if ($controlAttempt && in_array($run->status, [
        \App\Domain\Workflow\WorkflowStatus::Running,
        \App\Domain\Workflow\WorkflowStatus::Paused,
        \App\Domain\Workflow\WorkflowStatus::Failed,
    ], true))
        <section class="panel mt-6 overflow-hidden">
            <div class="grid gap-0 lg:grid-cols-[.8fr_1.2fr]">
                <div class="border-b border-white/[0.07] p-6 sm:p-7 lg:border-b-0 lg:border-r">
                    <div class="eyebrow"><span></span> Manual controls</div>
                    <h2 class="mt-4 text-xl font-semibold text-white">Recover or redirect this run</h2>
                    <p class="mt-2 text-sm leading-6 text-zinc-400">Use these controls to recover from an accidental decision or deliberately skip a stage. Orc never rewrites completed attempts.</p>

                    @if ($run->status === \App\Domain\Workflow\WorkflowStatus::Running && $controlAttempt->stage->type === \App\Domain\Workflow\StageType::Agent)
                        <form class="mt-5" method="POST" action="{{ route('workflows.attempts.pause', [$run, $controlAttempt]) }}" onsubmit="return confirm('Stop this agent attempt and pause the workflow?')">
                            @csrf
                            <button class="button-danger" type="submit">Stop current agent</button>
                            <p class="mt-2 text-xs leading-5 text-zinc-600">Sends a cancellation command to the bound Amp thread when one exists. The workflow remains resumable.</p>
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
                        <p class="mt-2 text-xs leading-5 text-zinc-400">Agent stages start a fresh thread and Orb and may spend tokens. Human Review waits for your decision. To finish, move to Human Review and use Approve.</p>
                        <button class="button-primary mt-5" type="submit">
                            {{ $run->status === \App\Domain\Workflow\WorkflowStatus::Running ? 'Stop & move' : 'Resume at stage' }}
                        </button>
                    </form>
                </div>
            </div>
        </section>
    @endif

    <div class="mt-8 grid gap-6 xl:grid-cols-[1.45fr_.8fr]">
        <section class="panel overflow-hidden">
            <div class="flex items-center justify-between border-b border-white/[0.07] px-6 py-5">
                <div><h2 class="font-semibold text-white">Stage attempts</h2><p class="mt-1 text-xs text-zinc-600">Immutable, sequential execution history</p></div>
                <span class="font-mono text-xs text-zinc-600">{{ $run->stageRuns->count() }} total</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[42rem] text-left">
                    <thead class="border-b border-white/[0.06] bg-black/10 font-mono text-[9px] uppercase tracking-[0.14em] text-zinc-700"><tr><th class="px-6 py-3 font-medium">Attempt</th><th class="px-4 py-3 font-medium">Stage</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 font-medium">Outcome</th><th class="px-6 py-3 text-right font-medium">Amp reference</th></tr></thead>
                    <tbody class="divide-y divide-white/[0.05]">
                        @foreach ($run->stageRuns as $attempt)
                            @php
                                $reportEvent = $run->events->first(fn ($event) => $event->stage_run_id === $attempt->id && isset($event->metadata['github_report_url']));
                                $attemptAwaitingController = $ampEnabled
                                    && $attempt->id === $active?->id
                                    && $attempt->stage->type === \App\Domain\Workflow\StageType::Agent
                                    && $attempt->ampLaunch?->launch_status === \App\Domain\Workflow\AmpLaunchStatus::Pending
                                    && ! $attempt->amp_thread_id;
                            @endphp
                            <tr class="text-sm">
                                <td class="px-6 py-4 font-mono text-xs text-zinc-500">#{{ str_pad($attempt->attempt_number, 2, '0', STR_PAD_LEFT) }}</td>
                                <td class="px-4 py-4 font-medium text-zinc-200">
                                    {{ $attempt->stage->name }}
                                    @if (($attempt->stage->config['agent_mode'] ?? null) === 'real_development')
                                        <span class="ml-2 rounded-full border border-sky-300/20 px-2 py-0.5 font-mono text-[8px] uppercase text-sky-200">real</span>
                                    @elseif (($attempt->stage->config['agent_mode'] ?? null) === 'real_qa')
                                        <span class="ml-2 whitespace-nowrap rounded-full border border-violet-300/20 px-2 py-0.5 font-mono text-[8px] uppercase text-violet-200">substantive QA</span>
                                    @elseif (isset($attempt->stage->config['agent_mode']))
                                        <span class="ml-2 rounded-full border border-amber-300/20 px-2 py-0.5 font-mono text-[8px] uppercase text-amber-200">proof</span>
                                    @endif
                                </td>
                                <td class="px-4 py-4"><span class="status-pill status-{{ $attemptAwaitingController ? 'pending' : $attempt->status->value }}">{{ $attemptAwaitingController ? 'waiting' : $attempt->status->value }}</span></td>
                                <td class="px-4 py-4 text-zinc-400">{{ $attempt->outcome ? str($attempt->outcome)->replace('_', ' ')->title() : '—' }}</td>
                                <td class="px-6 py-4 text-right text-xs">
                                    @if ($attempt->amp_thread_id)
                                        <a class="font-mono text-orange-300 hover:text-orange-200" href="https://ampcode.com/threads/{{ $attempt->amp_thread_id }}" target="_blank" rel="noopener">Thread ↗</a>
                                    @else
                                        <span class="font-mono text-[10px] text-zinc-700">{{ $ampEnabled ? ($attempt->ampLaunch?->launch_status?->value ?? 'Not launched') : 'Not connected' }}</span>
                                    @endif
                                    @if ($reportEvent)
                                        <a class="ml-3 text-zinc-400 hover:text-white" href="{{ $reportEvent->metadata['github_report_url'] }}" target="_blank" rel="noopener">Report ↗</a>
                                    @endif
                                    @if ($attempt->github_pull_request_url)
                                        <a class="ml-3 text-sky-300 hover:text-sky-200" href="{{ $attempt->github_pull_request_url }}" target="_blank" rel="noopener">PR #{{ $attempt->github_pull_request_number }} ↗</a>
                                    @endif
                                    @if ($attempt->github_branch)
                                        <a class="ml-3 text-zinc-500 hover:text-zinc-300" href="https://github.com/{{ $run->github_repository }}/tree/{{ rawurlencode($attempt->github_branch) }}" target="_blank" rel="noopener">Branch ↗</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel overflow-hidden">
            <div class="border-b border-white/[0.07] px-6 py-5"><h2 class="font-semibold text-white">Event timeline</h2><p class="mt-1 text-xs text-zinc-600">Append-only orchestration facts</p></div>
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
                            @if (isset($event->metadata['github_feedback_url']))
                                <a class="mt-2 inline-flex text-xs text-orange-300 hover:text-orange-200" href="{{ $event->metadata['github_feedback_url'] }}" target="_blank" rel="noopener">Open GitHub feedback ↗</a>
                            @endif
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
</x-app-layout>
