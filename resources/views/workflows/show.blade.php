<x-app-layout>
    <x-slot:title>{{ $run->github_repository }} #{{ $run->github_issue_number }}</x-slot:title>

    @php
        $active = $run->activeStageRun;
        $outcomes = $active?->stage?->outgoingTransitions ?? collect();
        $ampEnabled = (bool) config('services.amp.enabled');
        $activeLaunch = $active?->ampLaunch;
        $activeMode = $active?->stage?->config['agent_mode'] ?? null;
    @endphp

    <div class="flex flex-col justify-between gap-6 lg:flex-row lg:items-start">
        <div>
            <a href="{{ route('workflows.index') }}" class="inline-flex items-center gap-2 text-sm text-zinc-600 transition hover:text-zinc-300"><span>←</span> Workflows</a>
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
        @if ($run->status === \App\Domain\Workflow\WorkflowStatus::Running)
            <form method="POST" action="{{ route('workflows.cancel', $run) }}" onsubmit="return confirm('Cancel this workflow? This cannot be resumed.')">
                @csrf
                <button class="button-danger" type="submit">Cancel workflow</button>
            </form>
        @endif
    </div>

    <section class="panel mt-10 overflow-hidden px-5 py-6 sm:px-8">
        <div class="flex min-w-[42rem] items-start overflow-x-auto pb-2">
            @foreach ($run->definition->stages as $stage)
                @php
                    $isCurrent = $run->current_stage_id === $stage->id;
                    $wasVisited = $run->stageRuns->contains('workflow_stage_id', $stage->id);
                @endphp
                <div class="flex flex-1 items-start {{ $loop->last ? '' : 'after:mt-5 after:h-px after:min-w-8 after:flex-1 after:bg-white/10' }}">
                    <div class="min-w-[7rem]">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl border text-sm font-semibold {{ $isCurrent ? 'border-orange-400/50 bg-orange-400/10 text-orange-300 shadow-[0_0_24px_rgba(249,115,22,.12)]' : ($wasVisited ? 'border-emerald-400/20 bg-emerald-400/[0.07] text-emerald-400' : 'border-white/[0.08] bg-black/10 text-zinc-700') }}">
                            @if ($wasVisited && ! $isCurrent) ✓ @else {{ str_pad($stage->position, 2, '0', STR_PAD_LEFT) }} @endif
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
                                @if ($activeMode === 'real_development')
                                    A fresh private Amp thread and Orb is implementing the bound issue with normal Amp tools and your existing native repository access. Orc never provisions or copies GitHub credentials.
                                @else
                                    A fresh private Amp thread and Orb is running an integration proof. It has normal Amp tools but is instructed to make no code changes; its result is not code validation or approval.
                                @endif
                            @else
                                Amp execution is disabled. Use the explicit simulation control to test orchestration locally.
                            @endif
                        @else
                            Choose a permitted review outcome. Publish change feedback on GitHub before linking it here.
                        @endif
                    </p>
                    <div class="mt-6 flex items-center gap-2">
                        <span class="status-pill status-{{ $active->status->value }}">{{ $active->status->value }}</span>
                        <span class="font-mono text-[10px] uppercase tracking-wider text-zinc-600">{{ $active->stage->type->value }} stage</span>
                        @if ($activeMode === 'real_development')
                            <span class="rounded-full border border-sky-300/20 bg-sky-300/[0.08] px-2.5 py-1 font-mono text-[9px] uppercase tracking-wider text-sky-200">Real Development</span>
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
                                        <p class="text-xs leading-5 text-zinc-600">Stable launch key <span class="font-mono text-zinc-500">{{ $activeLaunch?->idempotency_key ? str($activeLaunch->idempotency_key)->limit(18) : 'preparing' }}</span></p>
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
                        @if ($run->definition->version >= 2 && $active->stage->key === 'human_review')
                            <div class="mb-4 rounded-xl border border-amber-300/20 bg-amber-300/[0.07] px-4 py-3 text-xs leading-5 text-amber-100/80">
                                <strong class="text-amber-200">Human release gate.</strong> QA was an integration proof only; no independent substantive code validation has occurred yet.
                            </div>
                        @endif
                        @foreach ($outcomes as $transition)
                            @if ($transition->outcome === 'request_changes')
                                <form method="POST" action="{{ route('workflows.attempts.human-action', [$run, $active]) }}" class="rounded-2xl border border-white/[0.08] bg-black/10 p-5">
                                    @csrf
                                    <input type="hidden" name="outcome" value="request_changes">
                                    <label class="field-label" for="github_feedback_url">GitHub feedback URL</label>
                                    <input class="field-input" type="url" name="github_feedback_url" id="github_feedback_url" placeholder="https://github.com/owner/repo/pull/…#discussion_r…" required>
                                    <p class="field-help">Required. Orc stores this link, never the feedback text.</p>
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
                                    <p class="text-sm font-medium text-sky-100">Retry Development</p>
                                    <p class="mt-1 text-xs leading-5 text-sky-200/60">Starts a new numbered attempt in a fresh thread and Orb. The agent rereads GitHub from scratch.</p>
                                    <button class="button-primary mt-4 bg-sky-500 hover:bg-sky-400" type="submit">Retry <span class="opacity-60">→ Real Development</span></button>
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
            <p class="mt-2 text-sm text-zinc-500">No transition was taken. Inspect the immutable attempt and event history below.</p>
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
                            @endphp
                            <tr class="text-sm">
                                <td class="px-6 py-4 font-mono text-xs text-zinc-500">#{{ str_pad($attempt->attempt_number, 2, '0', STR_PAD_LEFT) }}</td>
                                <td class="px-4 py-4 font-medium text-zinc-200">
                                    {{ $attempt->stage->name }}
                                    @if (($attempt->stage->config['agent_mode'] ?? null) === 'real_development')
                                        <span class="ml-2 rounded-full border border-sky-300/20 px-2 py-0.5 font-mono text-[8px] uppercase text-sky-200">real</span>
                                    @elseif (isset($attempt->stage->config['agent_mode']))
                                        <span class="ml-2 rounded-full border border-amber-300/20 px-2 py-0.5 font-mono text-[8px] uppercase text-amber-200">proof</span>
                                    @endif
                                </td>
                                <td class="px-4 py-4"><span class="status-pill status-{{ $attempt->status->value }}">{{ $attempt->status->value }}</span></td>
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
                            default => 'bg-orange-400',
                        };
                    @endphp
                    <li class="relative border-l border-white/[0.08] pb-6 pl-5 last:border-transparent last:pb-0">
                        <span class="absolute -left-1 top-1 h-2 w-2 rounded-full border-2 border-ink-900 {{ $eventDotClass }}"></span>
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-sm font-medium text-zinc-300">{{ str($event->type)->replace(['.', '_'], ' ')->title() }}</p>
                            <time class="shrink-0 font-mono text-[9px] text-zinc-700">{{ $event->happened_at->format('H:i:s') }}</time>
                        </div>
                        @if ($event->metadata)
                            <p class="mt-1 text-xs leading-5 text-zinc-600">
                                @if (isset($event->metadata['stage_key'])) {{ str($event->metadata['stage_key'])->replace('_', ' ')->title() }} @endif
                                @if (isset($event->metadata['attempt_number'])) · attempt {{ $event->metadata['attempt_number'] }} @endif
                                @if (isset($event->metadata['outcome'])) · {{ str($event->metadata['outcome'])->replace('_', ' ') }} @endif
                                @if (isset($event->metadata['source']) && $event->metadata['source'] === 'agent_simulation') · simulated @endif
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
