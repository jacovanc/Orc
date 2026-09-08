<x-app-layout>
    <x-slot:title>{{ $project->name }} settings</x-slot:title>
    @php($connection = $project->currentConnection)
    <div class="mx-auto max-w-5xl">
        <a href="{{ route('projects.show', $project) }}" class="text-sm text-zinc-500 hover:text-white">← {{ $project->name }}</a>
        <div class="mt-6 flex flex-wrap items-center justify-between gap-5">
            <div><div class="eyebrow"><span></span> Project routing</div><h1 class="mt-4 text-4xl font-semibold text-white">Amp connection</h1></div>
            <span class="status-pill status-{{ $connection?->status ?? 'pending' }}">{{ $connection?->status ?? 'not connected' }}</span>
        </div>

        @if (! auth()->user()->can_trigger_amp)
            <div class="mt-8 rounded-2xl border border-red-400/20 bg-red-400/[0.06] p-5 text-sm text-red-100/80">This immutable account is not permitted to launch Amp agents. Changing the profile email cannot grant this permission.</div>
        @endif

        <section class="panel mt-8 p-6 sm:p-8">
            <h2 class="font-semibold text-white">Current binding</h2>
            <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                <div><dt class="field-label">Canonical repository</dt><dd class="font-mono text-sm text-zinc-300">{{ $project->github_repository }}</dd></div>
                <div><dt class="field-label">Amp project identity</dt><dd class="font-mono text-sm text-zinc-300">{{ $connection?->amp_project_id ?? $project->amp_project_id }}</dd></div>
                <div><dt class="field-label">Connection ID</dt><dd class="break-all font-mono text-sm text-zinc-300">{{ $connection?->public_id ?? 'Not configured' }}</dd></div>
                <div><dt class="field-label">Version</dt><dd class="text-sm text-zinc-300">{{ $connection ? 'v'.$connection->version : '—' }}</dd></div>
                @if ($connection?->verification_thread_id)<div class="sm:col-span-2"><dt class="field-label">Verification thread</dt><dd><a class="font-mono text-sm text-orange-300" target="_blank" rel="noopener" href="https://ampcode.com/threads/{{ $connection->verification_thread_id }}">{{ $connection->verification_thread_id }} ↗</a></dd></div>@endif
            </dl>
            @if ($connection?->last_error_message)<p class="mt-5 rounded-xl border border-red-400/15 bg-red-400/[0.05] p-4 text-xs text-red-100/70">{{ $connection->last_error_message }}</p>@endif
            @if ($connection && $connection->status !== 'verified' && auth()->user()->can_trigger_amp)
                <form class="mt-6" method="POST" action="{{ route('projects.connections.verify', $project) }}">@csrf<button class="button-primary" type="submit">Verify in fresh Orb</button></form>
            @endif
        </section>

        <section class="panel mt-8 p-6 sm:p-8">
            <h2 class="font-semibold text-white">Save a new immutable connection version</h2>
            <p class="mt-2 text-sm leading-6 text-zinc-500">Create two different random secrets outside Orc. Put the same values in this form and the selected Amp project’s owner-only <code>.amp/runtime/orc-plugin.json</code>. Values are encrypted at rest and never shown again.</p>
            <form class="mt-7 grid gap-5" method="POST" action="{{ route('projects.connections.store', $project) }}">
                @csrf
                <div><label class="field-label" for="amp_project_id">Amp project ID</label><input class="field-input font-mono" id="amp_project_id" name="amp_project_id" value="{{ old('amp_project_id', $project->amp_project_id) }}" required></div>
                <div><label class="field-label" for="launch_webhook_url">Amp controller webhook URL</label><input class="field-input" type="url" id="launch_webhook_url" name="launch_webhook_url" value="{{ old('launch_webhook_url') }}" placeholder="https://…ampcode.com/…" required></div>
                <div class="grid gap-5 sm:grid-cols-2"><div><label class="field-label" for="launch_signing_secret">Orc → controller secret</label><input class="field-input" type="password" autocomplete="new-password" id="launch_signing_secret" name="launch_signing_secret" required></div><div><label class="field-label" for="callback_signing_secret">Controller → Orc secret</label><input class="field-input" type="password" autocomplete="new-password" id="callback_signing_secret" name="callback_signing_secret" required></div></div>
                <div><button class="button-primary" type="submit" @disabled(! auth()->user()->can_trigger_amp)>Save version</button></div>
            </form>
        </section>

        <section class="mt-8 rounded-2xl border border-sky-300/15 bg-sky-300/[0.04] p-6 text-sm leading-6 text-sky-100/70">
            <strong class="text-sky-100">Placement matters.</strong> Install/reload the controller from inside the chosen Amp project; <code>createThread</code> has no project selector. Verification accepts only a fresh child reporting this exact Amp project identity and native read access to <code>{{ $project->github_repository }}</code>. Orc does not copy or repair GitHub credentials.
        </section>
    </div>
</x-app-layout>
