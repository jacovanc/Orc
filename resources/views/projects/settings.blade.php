<x-app-layout>
    <x-slot:title>{{ $project->name }} settings</x-slot:title>
    @php($connection = $project->currentConnection)
    <div class="mx-auto max-w-5xl">
        <a href="{{ route('projects.show', $project) }}" class="text-sm text-zinc-500 hover:text-white">← {{ $project->name }}</a>
        <div class="mt-6 flex flex-wrap items-center justify-between gap-5">
            <div><div class="eyebrow"><span></span> Project routing</div><h1 class="mt-4 text-4xl font-semibold text-white">Amp connection</h1></div>
            <span class="status-pill status-{{ $connection?->status ?? 'pending' }}">{{ str($connection?->status ?? 'not connected')->replace('_', ' ') }}</span>
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
            @if ($connection && $connection->launch_webhook_url && $connection->status !== 'verified' && auth()->user()->can_trigger_amp)
                <form class="mt-6" method="POST" action="{{ route('projects.connections.verify', $project) }}">@csrf<button class="button-primary" type="submit">Verify in fresh Orb</button></form>
            @endif
        </section>

        <section class="panel mt-8 p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-5">
                <div><h2 class="font-semibold text-white">Agent-assisted setup</h2><p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-500">Copy this prompt into an already-authenticated agent in <strong class="text-zinc-300">{{ $project->amp_project_id }}</strong>. No webhook URL or signing secret needs to be copied by hand.</p></div>
                @if ($setup)<span class="status-pill status-{{ $setup->displayStatus() }}">setup {{ $setup->displayStatus() }}</span>@endif
            </div>

            @if ($setupPrompt)
                <div class="mt-7" x-data="{ copied: false }">
                    <textarea id="amp-setup-prompt" class="field-input min-h-[32rem] resize-y font-mono text-xs leading-5" readonly>{{ $setupPrompt }}</textarea>
                    <div class="mt-4 flex flex-wrap items-center gap-4">
                        <button class="button-primary" type="button" @click="navigator.clipboard.writeText(document.getElementById('amp-setup-prompt').value); copied = true; setTimeout(() => copied = false, 1800)"><span x-text="copied ? 'Copied' : 'Copy setup prompt'">Copy setup prompt</span></button>
                        <span class="text-xs text-zinc-600">Expires {{ $setup->expires_at->diffForHumans() }} · single connection and Amp project only</span>
                    </div>
                    @if ($setup->status === 'claimed')
                        <p class="mt-4 rounded-xl border border-sky-300/15 bg-sky-300/[0.04] p-4 text-xs leading-5 text-sky-100/70">The setup agent claimed this prompt. If it asks for a plugin reload, run <strong class="text-sky-100">plugins: reload</strong> in that same Amp thread, then tell it to continue.</p>
                    @endif
                </div>
            @elseif ($connection?->status === 'verified')
                <p class="mt-6 rounded-xl border border-emerald-400/15 bg-emerald-400/[0.05] p-4 text-sm text-emerald-100/70">This immutable connection version is verified and ready for workflows.</p>
            @else
                <p class="mt-6 rounded-xl border border-amber-300/15 bg-amber-300/[0.05] p-4 text-sm text-amber-100/70">This setup prompt is unavailable, expired, or consumed. Generate a new immutable connection version to retry safely.</p>
            @endif

            <form class="mt-6" method="POST" action="{{ route('projects.connections.setup', $project) }}">
                @csrf
                <button class="button-quiet" type="submit" @disabled(! auth()->user()->can_trigger_amp)>{{ $connection ? 'Generate new setup prompt' : 'Generate setup prompt' }}</button>
                <p class="mt-2 text-xs text-zinc-600">Reissuing revokes the previous unused capability. Existing workflow runs stay bound to their original connection version.</p>
            </form>
        </section>

        <section class="mt-8 rounded-2xl border border-sky-300/15 bg-sky-300/[0.04] p-6 text-sm leading-6 text-sky-100/70">
            <strong class="text-sky-100">Placement matters.</strong> Install/reload the controller from inside the chosen Amp project; <code>createThread</code> has no project selector. Verification accepts only a fresh child reporting this exact Amp project identity and native read access to <code>{{ $project->github_repository }}</code>. Orc does not copy or repair GitHub credentials.
        </section>
    </div>
</x-app-layout>
