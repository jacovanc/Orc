<x-app-layout>
    <x-slot:title>{{ $project->name }}</x-slot:title>
    <div class="flex flex-col justify-between gap-6 sm:flex-row sm:items-end">
        <div>
            <a href="{{ route('projects.index') }}" class="text-sm text-zinc-500 hover:text-white">← Projects</a>
            <div class="mt-6 flex items-center gap-3"><span class="status-pill status-{{ $project->currentConnection?->status ?? 'pending' }}">{{ $project->currentConnection?->status ?? 'not connected' }}</span><span class="font-mono text-xs text-zinc-500">{{ $project->github_repository }}</span></div>
            <h1 class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-white">{{ $project->name }}</h1>
        </div>
        <div class="flex gap-3">
            <a class="button-quiet" href="{{ route('projects.settings', $project) }}">Settings</a>
            <a class="button-primary" href="{{ route('projects.workflows.create', $project) }}">Start workflow</a>
        </div>
    </div>

    <section class="mt-10">
        @forelse ($runs as $run)
            <a href="{{ route('workflows.show', $run) }}" class="group mb-3 flex items-center justify-between gap-5 rounded-2xl border border-white/[0.07] bg-ink-900/70 p-5 transition hover:border-white/[0.14]">
                <div><h2 class="font-semibold text-zinc-100">Issue #{{ $run->github_issue_number }}</h2><p class="mt-1 text-xs text-zinc-500">RUN-{{ str_pad($run->id, 4, '0', STR_PAD_LEFT) }} · {{ $run->definition->name }} v{{ $run->definition->version }} · {{ $run->currentStage?->name }}</p></div>
                <span class="status-pill status-{{ $run->displayStatusClass() }}">{{ $run->displayStatus() }}</span>
            </a>
        @empty
            <div class="panel py-20 text-center"><h2 class="font-semibold text-white">No runs in this project</h2><p class="mt-2 text-sm text-zinc-500">Connect and verify the Amp controller, then start from a GitHub issue.</p></div>
        @endforelse
        @if ($runs->hasPages())<div class="mt-8">{{ $runs->links() }}</div>@endif
    </section>
</x-app-layout>
