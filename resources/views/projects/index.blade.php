<x-app-layout>
    <x-slot:title>Projects</x-slot:title>

    <section class="flex flex-col justify-between gap-8 lg:flex-row lg:items-end">
        <div>
            <div class="eyebrow"><span></span> Personal delivery spaces</div>
            <h1 class="mt-5 text-4xl font-semibold tracking-[-0.045em] text-white sm:text-5xl">Projects</h1>
            <p class="mt-3 max-w-2xl text-base leading-7 text-zinc-500">Each project binds one GitHub repository to its own Amp project controller. Runs never move when connection settings change.</p>
        </div>
    </section>

    <section class="mt-10 grid gap-4 lg:grid-cols-2">
        @foreach ($projects as $project)
            <a href="{{ route('projects.show', $project) }}" class="panel group p-6 transition hover:-translate-y-0.5 hover:border-white/[0.14]">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-white group-hover:text-orange-200">{{ $project->name }}</h2>
                        <p class="mt-2 font-mono text-xs text-zinc-500">{{ $project->github_repository }}</p>
                    </div>
                    <span class="status-pill status-{{ $project->currentConnection?->status ?? 'pending' }}">{{ $project->currentConnection?->status ?? 'not connected' }}</span>
                </div>
                <div class="mt-7 flex items-center gap-6 text-xs text-zinc-500">
                    <span><strong class="mr-1 text-zinc-300">{{ $project->running_workflow_runs_count }}</strong> running</span>
                    <span><strong class="mr-1 text-zinc-300">{{ $project->workflow_runs_count }}</strong> total runs</span>
                    <span class="ml-auto text-zinc-400">Open →</span>
                </div>
            </a>
        @endforeach
    </section>

    <section class="panel mt-8 p-6 sm:p-8">
        <h2 class="text-lg font-semibold text-white">Add a project</h2>
        <p class="mt-2 text-sm text-zinc-500">The Amp project must already exist and have user-configured native access to this repository.</p>
        <form method="POST" action="{{ route('projects.store') }}" class="mt-6 grid gap-5 lg:grid-cols-[1fr_1.2fr_1.2fr_auto] lg:items-end">
            @csrf
            <div><label class="field-label" for="name">Name</label><input class="field-input" id="name" name="name" value="{{ old('name') }}" required></div>
            <div><label class="field-label" for="github_repository">GitHub repository</label><input class="field-input" id="github_repository" name="github_repository" placeholder="owner/repository" value="{{ old('github_repository') }}" required></div>
            <div><label class="field-label" for="amp_project_id">Amp project ID</label><input class="field-input font-mono" id="amp_project_id" name="amp_project_id" value="{{ old('amp_project_id') }}" required></div>
            <button class="button-primary" type="submit">Create</button>
        </form>
    </section>
</x-app-layout>
