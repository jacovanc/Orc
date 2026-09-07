<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name', 'Orc') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-ink-950 font-sans text-zinc-100 antialiased">
        <div class="ambient-glow" aria-hidden="true"></div>
        @include('layouts.navigation')

        <main class="relative mx-auto w-full max-w-7xl px-5 pb-20 pt-10 sm:px-8 lg:px-10 lg:pt-14">
            @if (session('status'))
                <div class="mb-6 flex items-center gap-3 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200" role="status">
                    <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-2xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-100" role="alert">
                    <p class="font-semibold">The workflow was not changed.</p>
                    <ul class="mt-1 list-inside list-disc text-red-200/80">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </main>
    </body>
</html>
