<x-app-layout>
    <x-slot:title>Start workflow</x-slot:title>

    <div class="mx-auto max-w-4xl">
        <a href="{{ route('projects.show', $project) }}" class="inline-flex items-center gap-2 text-sm text-zinc-500 transition hover:text-zinc-200"><span>←</span> {{ $project->name }}</a>
        <div class="mt-7">
            <div class="eyebrow"><span></span> New run</div>
            <h1 class="mt-4 text-4xl font-semibold tracking-[-0.045em] text-white">Start a workflow</h1>
            <p class="mt-3 text-zinc-500">Start from an issue in <span class="font-mono text-zinc-300">{{ $project->github_repository }}</span>. Only identifiers and orchestration state are stored here.</p>
        </div>

        <form method="POST" action="{{ route('projects.workflows.store', $project) }}" class="panel mt-10 overflow-hidden">
            @csrf
            <div class="border-b border-white/[0.07] p-6 sm:p-8">
                <p class="font-mono text-xs uppercase tracking-[0.16em] text-orange-400">01 · Workflow</p>
                <div class="mt-5 grid gap-3">
                    @forelse ($definitions as $definition)
                        <label class="group cursor-pointer">
                            <input type="radio" class="peer sr-only" name="workflow_definition_id" value="{{ $definition->id }}" @checked(old('workflow_definition_id', $definitions->first()?->id) == $definition->id)>
                            <div class="rounded-2xl border border-white/[0.08] bg-black/10 p-5 transition group-hover:border-white/[0.16] peer-checked:border-orange-400/50 peer-checked:bg-orange-400/[0.06]">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <h2 class="font-semibold text-white">{{ $definition->name }}</h2>
                                        <p class="mt-1 text-xs text-zinc-600">Version {{ $definition->version }} · immutable definition</p>
                                    </div>
                                    <span class="rounded-full border border-white/10 px-2.5 py-1 font-mono text-[10px] uppercase text-zinc-500">{{ $definition->stages->count() }} stages</span>
                                </div>
                                <div class="mt-5 flex flex-wrap items-center gap-2 text-xs text-zinc-400">
                                    @foreach ($definition->stages as $stage)
                                        <span class="rounded-lg border border-white/[0.07] bg-black/20 px-2.5 py-1.5">{{ $stage->name }}</span>
                                        @if (! $loop->last)<span class="text-zinc-700">→</span>@endif
                                    @endforeach
                                </div>
                            </div>
                        </label>
                    @empty
                        <p class="rounded-xl border border-amber-400/20 bg-amber-400/10 p-4 text-sm text-amber-200">No active workflow definition is available. Run the database seeder first.</p>
                    @endforelse
                </div>
            </div>

            <div class="p-6 sm:p-8">
                <p class="font-mono text-xs uppercase tracking-[0.16em] text-orange-400">02 · GitHub issue</p>
                <div class="mt-6 grid gap-6 sm:grid-cols-[1fr_12rem]">
                    <div><label class="field-label">Repository</label><div class="field-input font-mono text-zinc-400">{{ $project->github_repository }}</div><p class="field-help">Fixed by the project’s canonical binding.</p></div>
                    <div>
                        <label class="field-label" for="github_issue_number">Issue number</label>
                        <input class="field-input" type="number" min="1" id="github_issue_number" name="github_issue_number" value="{{ old('github_issue_number') }}" placeholder="42" required>
                        <p class="field-help">Orc derives the issue link automatically.</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-4 border-t border-white/[0.07] bg-black/10 px-6 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                <p class="text-xs leading-5 text-zinc-600">
                    @if (config('services.amp.enabled'))
                        Starts in a fresh Amp thread and Orb. Repository access must already be configured in Amp; Orc never provisions credentials.
                    @else
                        Starts immediately in Development using local simulation controls.
                    @endif
                </p>
                <button class="button-primary" type="submit" @disabled($definitions->isEmpty())>Start workflow <span>→</span></button>
            </div>
        </form>
    </div>
</x-app-layout>
