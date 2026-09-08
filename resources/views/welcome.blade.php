<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Orc · Workflow orchestration</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen overflow-x-hidden bg-ink-950 font-sans text-zinc-100 antialiased">
    <div class="ambient-glow" aria-hidden="true"></div>
    <nav class="relative z-10 mx-auto flex h-20 max-w-7xl items-center justify-between px-6 lg:px-10">
        <a href="/" class="flex items-center gap-3"><span class="logo-mark"><span></span><span></span><span></span></span><span class="text-xl font-bold tracking-[-0.04em]">Orc</span></a>
        <div class="flex items-center gap-2">
            @auth
                <a href="{{ route('workflows.index') }}" class="button-primary">Open workspace</a>
            @else
                <a href="{{ route('login') }}" class="button-quiet">Sign in</a>
                <a href="{{ route('register') }}" class="button-primary">Create account</a>
            @endauth
        </div>
    </nav>
    <main class="relative mx-auto max-w-7xl px-6 pb-24 pt-20 lg:px-10 lg:pt-28">
        <div class="max-w-3xl">
            <div class="eyebrow"><span></span> GitHub-native delivery</div>
            <h1 class="mt-7 text-5xl font-semibold leading-[0.96] tracking-[-0.06em] text-white sm:text-7xl">Move work forward.<br><span class="text-zinc-500">Keep context where it belongs.</span></h1>
            <p class="mt-8 max-w-2xl text-lg leading-8 text-zinc-400">Orc coordinates agent execution, QA loops, and human review while GitHub remains the source of truth for requirements, code, discussion, and reports.</p>
            <div class="mt-10 flex flex-wrap gap-3">
                <a href="{{ auth()->check() ? route('workflows.create') : route('register') }}" class="button-primary px-5 py-3">Start orchestrating <span aria-hidden="true">→</span></a>
                <a href="https://github.com" class="button-quiet border border-white/10 px-5 py-3">Built around GitHub</a>
            </div>
        </div>
        <div class="mt-20 grid gap-px overflow-hidden rounded-3xl border border-white/[0.08] bg-white/[0.08] lg:grid-cols-3">
            @foreach ([['01', 'Agent execution', 'A focused development stage advances only on an explicit outcome.'], ['02', 'Quality loops', 'Failed QA returns to development as a new, numbered attempt.'], ['03', 'Human control', 'Reviewers approve or request changes after reviewing the pull request on GitHub.']] as [$number, $heading, $copy])
                <article class="bg-ink-900/90 p-7 backdrop-blur-sm sm:p-9">
                    <p class="font-mono text-xs text-orange-400">{{ $number }}</p>
                    <h2 class="mt-8 text-xl font-semibold tracking-tight text-white">{{ $heading }}</h2>
                    <p class="mt-3 text-sm leading-6 text-zinc-500">{{ $copy }}</p>
                </article>
            @endforeach
        </div>
    </main>
</body>
</html>
