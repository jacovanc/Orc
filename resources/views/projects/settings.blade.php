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
                <div><dt class="field-label">Amp project identity</dt><dd class="font-mono text-sm text-zinc-300">{{ $connection?->amp_project_id ?? $project->amp_project_id ?? 'Discovered when setup is claimed' }}</dd></div>
                <div><dt class="field-label">Connection ID</dt><dd class="break-all font-mono text-sm text-zinc-300">{{ $connection?->public_id ?? 'Not configured' }}</dd></div>
                <div><dt class="field-label">Version</dt><dd class="text-sm text-zinc-300">{{ $connection ? 'v'.$connection->version : '—' }}</dd></div>
                <div><dt class="field-label">Controller protocol</dt><dd class="text-sm text-zinc-300">{{ $connection ? 'v'.$connection->controller_protocol_version : '—' }}{{ $connection && $connection->controller_protocol_version < 3 ? ' · Explanation unavailable' : '' }}</dd></div>
                <div><dt class="field-label">Last verification</dt><dd class="text-sm text-zinc-300">{{ $connection?->verified_at?->diffForHumans() ?? 'Not verified' }}</dd></div>
                @if ($connection?->controller_thread_id)
                    <div class="sm:col-span-2"><dt class="field-label">Dedicated controller thread</dt><dd><a class="font-mono text-sm text-orange-300" target="_blank" rel="noopener" href="https://ampcode.com/threads/{{ $connection->controller_thread_id }}">{{ $connection->controller_thread_id }} ↗</a><span class="ml-3 text-xs text-zinc-600">Last acknowledged {{ $connection->controller_last_acknowledged_at?->diffForHumans() ?? 'not yet' }}</span></dd></div>
                @else
                    <div class="sm:col-span-2"><dt class="field-label">Dedicated controller thread</dt><dd class="text-sm text-zinc-500">Awaiting an actual webhook-handler acknowledgement. A queued HTTP 202 does not identify or verify the owner.</dd></div>
                @endif
                @if ($connection?->verification_thread_id)<div class="sm:col-span-2"><dt class="field-label">Verification thread</dt><dd><a class="font-mono text-sm text-orange-300" target="_blank" rel="noopener" href="https://ampcode.com/threads/{{ $connection->verification_thread_id }}">{{ $connection->verification_thread_id }} ↗</a></dd></div>@endif
            </dl>
            @if ($connection?->last_error_message)<p class="mt-5 rounded-xl border border-red-400/15 bg-red-400/[0.05] p-4 text-xs text-red-100/70">{{ $connection->last_error_message }}</p>@endif
            @if ($connection && $connection->controller_protocol_version < 2)
                <p class="mt-5 rounded-xl border border-amber-300/15 bg-amber-300/[0.05] p-4 text-xs leading-5 text-amber-100/70">This historical controller remains valid for existing workflows, but cannot launch the new Merge stage. Generate and pair a new immutable setup prompt to enable workflow v4; existing runs stay on this connection version.</p>
            @elseif ($connection && $connection->controller_protocol_version < 3)
                <p class="mt-5 rounded-xl border border-amber-300/15 bg-amber-300/[0.05] p-4 text-xs leading-5 text-amber-100/70">This controller remains valid for workflow v4, but cannot launch Explanation or deliver versioned task bodies. Pair a new immutable connection to enable workflow v5; existing runs stay unchanged.</p>
            @endif
            @if ($connection && $connection->launch_webhook_url && $connection->status !== 'verified' && auth()->user()->can_trigger_amp)
                <form class="mt-6" method="POST" action="{{ route('projects.connections.verify', $project) }}">@csrf<button class="button-primary" type="submit">Verify in fresh Orb</button></form>
            @endif
        </section>

        <section class="panel mt-8 p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-5">
                <div><h2 class="font-semibold text-white">Agent-assisted setup</h2><p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-500">Copy this self-bootstrapping prompt into an already-authenticated agent in the Amp project you want to link. It installs Orc into a new Amp account when needed, then binds the identity reported by that project; no ID, webhook URL, or signing secret needs to be copied by hand.</p></div>
                @if ($setup)<span class="status-pill status-{{ $setup->displayStatus() }}">setup {{ $setup->displayStatus() }}</span>@endif
            </div>

            @if ($setupPrompt)
                <div class="mt-7" x-data="{ copied: false }">
                    <textarea id="amp-setup-prompt" class="field-input min-h-[32rem] resize-y font-mono text-xs leading-5" readonly>{{ $setupPrompt }}</textarea>
                    <div class="mt-4 flex flex-wrap items-center gap-4">
                        <button class="button-primary" type="button" @click="navigator.clipboard.writeText(document.getElementById('amp-setup-prompt').value); copied = true; setTimeout(() => copied = false, 1800)"><span x-text="copied ? 'Copied' : 'Copy setup prompt'">Copy setup prompt</span></button>
                        <span class="text-xs text-zinc-600">Expires {{ $setup->expires_at->diffForHumans() }} · single connection, thread, and Amp project only</span>
                    </div>
                    <p class="mt-4 text-xs leading-5 text-zinc-600">A fresh Amp account needs no Orc prerequisite. The agent first publishes Orc’s pinned worker to your Personal Plugins, reloads plugins itself when supported, and then performs project pairing. It asks you to reload only when it cannot.</p>
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

        <section class="panel mt-8 p-6 sm:p-8" id="stage-instructions">
            <div class="flex flex-wrap items-start justify-between gap-5">
                <div>
                    <h2 class="font-semibold text-white">Agent task bodies</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-500">This is the substantive task text delivered to each agent. Orc adds a separate fixed security and evidence envelope that cannot be edited here. Saving creates a new project-scoped version; only workflows started afterward use it. Active runs and every retry keep their original snapshots.</p>
                </div>
                <span class="rounded-full border border-white/10 px-3 py-1 font-mono text-[10px] uppercase tracking-wider text-zinc-500">Plain text · no templates</span>
            </div>
            <div class="mt-7 space-y-5">
                @foreach ($stageInstructions as $instruction)
                    <form method="POST" action="{{ route('projects.stage-instructions.update', $project) }}" class="rounded-2xl border border-white/[0.08] bg-black/10 p-5" data-stage-instruction="{{ $instruction['agent_mode'] }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="agent_mode" value="{{ $instruction['agent_mode'] }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <label class="font-medium text-zinc-100" for="instruction-{{ $instruction['agent_mode'] }}">{{ $instruction['label'] }}</label>
                                <p class="mt-1 font-mono text-[10px] uppercase tracking-wider text-zinc-600">Task body v{{ $instruction['version'] }} · {{ $instruction['source'] }}</p>
                            </div>
                            <button class="button-quiet" type="submit" @disabled(! auth()->user()->can_trigger_amp)>Save new version</button>
                        </div>
                        <textarea class="field-input mt-4 min-h-36 resize-y font-mono text-xs leading-5" id="instruction-{{ $instruction['agent_mode'] }}" name="body" maxlength="{{ \App\Services\StageTaskInstructionService::MAX_BODY_LENGTH }}" required>{{ old('agent_mode') === $instruction['agent_mode'] ? old('body') : $instruction['body'] }}</textarea>
                        <p class="mt-3 text-xs leading-5 text-zinc-500"><strong class="text-zinc-400">Fixed permission boundary:</strong> {{ $instruction['boundary'] }}</p>
                    </form>
                @endforeach
            </div>
        </section>

        <section class="mt-8 rounded-2xl border border-sky-300/15 bg-sky-300/[0.04] p-6 text-sm leading-6 text-sky-100/70">
            <strong class="text-sky-100">Placement matters.</strong> Install/reload the controller from inside the chosen Amp project; <code>createThread</code> has no project selector. The first setup claim binds that actual Amp project identity, and verification accepts only a fresh Orb reporting the same identity and native read access to <code>{{ $project->github_repository }}</code>. Orc does not copy or repair GitHub credentials.
        </section>

        <section class="mt-6 rounded-2xl border border-amber-300/15 bg-amber-300/[0.04] p-6 text-sm leading-6 text-amber-100/70">
            <strong class="text-amber-100">Controller lifecycle.</strong> Keep the dedicated controller thread above unarchived. Normal idle Orb sleep is safe—an incoming event wakes it, so no keep-alive or polling is needed. If the webhook becomes unavailable, the cause is not knowable from HTTP 404 alone: open and restore the owning thread if archived, resume the trigger in Amp settings if it was separately paused, then click <em>Verify in fresh Orb</em>. If recovery fails, generate a new immutable connection version. Existing runs remain bound to their original version and never move silently.
        </section>
    </div>
</x-app-layout>
