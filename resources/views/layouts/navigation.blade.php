<nav x-data="{ open: false }" class="relative z-20 border-b border-white/[0.07] bg-ink-950/80 backdrop-blur-xl">
    <div class="mx-auto flex h-20 max-w-7xl items-center justify-between px-5 sm:px-8 lg:px-10">
        <div class="flex items-center gap-10">
            <a href="{{ route('workflows.index') }}" class="group flex items-center gap-3" aria-label="Orc workflows">
                <span class="logo-mark"><span></span><span></span><span></span></span>
                <span class="text-xl font-bold tracking-[-0.04em] text-white">Orc</span>
            </a>
            <div class="hidden items-center gap-1 md:flex">
                <a href="{{ route('projects.index') }}" class="nav-item {{ request()->routeIs('projects.*') ? 'nav-item-active' : '' }}">Projects</a>
                <a href="{{ route('workflows.index') }}" class="nav-item {{ request()->routeIs('workflows.index', 'workflows.show') ? 'nav-item-active' : '' }}">Workflows</a>
                <a href="{{ route('workflows.create') }}" class="nav-item {{ request()->routeIs('workflows.create') ? 'nav-item-active' : '' }}">Start run</a>
            </div>
        </div>

        <div class="hidden items-center gap-4 md:flex">
            <div class="text-right">
                <p class="text-sm font-medium text-zinc-200">{{ Auth::user()->name }}</p>
                <p class="text-xs text-zinc-500">Orchestrator</p>
            </div>
            <div class="flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-white/5 text-sm font-semibold text-zinc-300">
                {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="rounded-lg px-3 py-2 text-xs font-medium text-zinc-500 transition hover:bg-white/5 hover:text-zinc-200">Sign out</button>
            </form>
        </div>

        <button @click="open = ! open" class="rounded-xl border border-white/10 p-2 text-zinc-300 md:hidden" aria-label="Toggle menu">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-width="1.5" d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
    </div>
    <div x-show="open" x-cloak class="border-t border-white/[0.07] px-5 py-4 md:hidden">
        <a href="{{ route('projects.index') }}" class="block rounded-lg px-3 py-2 text-sm text-zinc-200">Projects</a>
        <a href="{{ route('workflows.index') }}" class="block rounded-lg px-3 py-2 text-sm text-zinc-200">Workflows</a>
        <a href="{{ route('workflows.create') }}" class="block rounded-lg px-3 py-2 text-sm text-zinc-200">Start run</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="mt-2 block px-3 py-2 text-sm text-zinc-500">Sign out</button></form>
    </div>
</nav>
