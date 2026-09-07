<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'Orc') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-ink-950 font-sans text-zinc-100 antialiased">
        <div class="ambient-glow" aria-hidden="true"></div>
        <div class="relative flex min-h-screen flex-col items-center justify-center px-5 py-12">
            <a href="/" class="mb-8 flex items-center gap-3">
                <span class="logo-mark"><span></span><span></span><span></span></span>
                <span class="text-2xl font-bold tracking-[-0.04em] text-white">Orc</span>
            </a>
            <div class="auth-card w-full max-w-md rounded-3xl border border-white/[0.08] bg-ink-900/90 px-7 py-8 shadow-2xl shadow-black/30 backdrop-blur-xl sm:px-9">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
