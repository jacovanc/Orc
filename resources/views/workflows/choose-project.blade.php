<x-app-layout>
    <x-slot:title>Choose project</x-slot:title>
    <div class="mx-auto max-w-4xl"><div class="eyebrow"><span></span> New run</div><h1 class="mt-4 text-4xl font-semibold text-white">Choose a project</h1><div class="mt-8 grid gap-3">
        @foreach ($projects as $project)
            <a class="panel flex items-center justify-between p-5 hover:border-orange-400/30" href="{{ route('projects.workflows.create', $project) }}"><div><p class="font-semibold text-white">{{ $project->name }}</p><p class="mt-1 font-mono text-xs text-zinc-600">{{ $project->github_repository }}</p></div><span class="text-zinc-500">→</span></a>
        @endforeach
    </div></div>
</x-app-layout>
