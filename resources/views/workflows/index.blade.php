<x-app-layout>
    <x-slot:title>Workflows</x-slot:title>

    <section class="flex flex-col justify-between gap-8 sm:flex-row sm:items-end">
        <div>
            <div class="eyebrow"><span></span> Delivery control plane</div>
            <h1 class="mt-5 text-4xl font-semibold tracking-[-0.045em] text-white sm:text-5xl">Workflows</h1>
            <p class="mt-3 max-w-xl text-base leading-7 text-zinc-500">All-project overview. Track delivery without moving requirements, code, or discussion out of GitHub.</p>
        </div>
        <a href="{{ route('workflows.create') }}" class="button-primary self-start sm:self-auto">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-width="2" d="M12 5v14M5 12h14"/></svg>
            Start workflow
        </a>
    </section>

    <section class="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ([['running', 'In progress', 'bg-orange-400'], ['paused', 'Paused', 'bg-sky-300'], ['completed', 'Completed', 'bg-emerald-400'], ['failed', 'Failed', 'bg-red-400'], ['cancelled', 'Cancelled', 'bg-zinc-400']] as [$key, $label, $dotClass])
            <div class="panel flex items-center justify-between px-5 py-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-[0.12em] text-zinc-600">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold text-white">{{ $counts[$key] ?? 0 }}</p>
                </div>
                <span class="h-2.5 w-2.5 rounded-full {{ $dotClass }} shadow-[0_0_18px_currentColor]"></span>
            </div>
        @endforeach
    </section>

    <section class="mt-8">
        @forelse ($runs as $run)
            <a href="{{ route('workflows.show', $run) }}" class="group mb-3 grid gap-5 rounded-2xl border border-white/[0.07] bg-ink-900/70 p-5 transition hover:-translate-y-0.5 hover:border-white/[0.14] hover:bg-ink-850/90 sm:grid-cols-[1fr_auto] sm:items-center lg:grid-cols-[1.4fr_.8fr_.7fr_auto]">
                <div class="min-w-0">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-white/[0.08] bg-black/20 text-zinc-500">
                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 .7a12 12 0 0 0-3.8 23.4c.6.1.8-.3.8-.6v-2.3c-3.3.7-4-1.4-4-1.4-.5-1.4-1.3-1.8-1.3-1.8-1.1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1.1 1.8 2.8 1.3 3.5 1 .1-.8.4-1.3.8-1.6-2.7-.3-5.5-1.3-5.5-5.9 0-1.3.5-2.4 1.2-3.2-.1-.3-.5-1.5.1-3.2 0 0 1-.3 3.3 1.2a11.4 11.4 0 0 1 6 0c2.3-1.5 3.3-1.2 3.3-1.2.6 1.7.2 2.9.1 3.2.8.8 1.2 1.9 1.2 3.2 0 4.6-2.8 5.6-5.5 5.9.4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6A12 12 0 0 0 12 .7Z"/></svg>
                        </span>
                        <div class="min-w-0">
                            <h2 class="truncate font-semibold text-zinc-100 transition group-hover:text-orange-300">{{ $run->github_repository }} <span class="text-zinc-600">#{{ $run->github_issue_number }}</span></h2>
                            <p class="mt-1 text-xs text-zinc-600">Run {{ str_pad($run->id, 4, '0', STR_PAD_LEFT) }} · {{ $run->definition->name }} v{{ $run->definition->version }}</p>
                        </div>
                    </div>
                </div>
                <div>
                    <p class="text-[10px] font-medium uppercase tracking-wider text-zinc-700">Current stage</p>
                    <p class="mt-1 text-sm text-zinc-300">{{ $run->currentStage?->name ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-medium uppercase tracking-wider text-zinc-700">Started</p>
                    <p class="mt-1 text-sm text-zinc-400">{{ $run->started_at?->diffForHumans() }}</p>
                </div>
                <div class="flex items-center gap-4">
                    <span class="status-pill status-{{ $run->displayStatusClass() }}">{{ $run->displayStatus() }}</span>
                    <span class="text-zinc-700 transition group-hover:translate-x-1 group-hover:text-zinc-300">→</span>
                </div>
            </a>
        @empty
            <div class="panel flex min-h-80 flex-col items-center justify-center px-6 text-center">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl border border-dashed border-orange-400/30 bg-orange-400/[0.06] text-orange-300">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7h11M4 12h16M4 17h8"/></svg>
                </div>
                <h2 class="mt-5 text-lg font-semibold text-white">No workflows yet</h2>
                <p class="mt-2 max-w-sm text-sm leading-6 text-zinc-500">Choose a GitHub issue and Orc will coordinate its delivery stages.</p>
                <a href="{{ route('workflows.create') }}" class="button-primary mt-6">Start your first workflow</a>
            </div>
        @endforelse

        @if ($runs->hasPages())
            <div class="mt-8">{{ $runs->links() }}</div>
        @endif
    </section>
</x-app-layout>
