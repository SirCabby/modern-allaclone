<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Runs before first paint so the saved theme is applied without a flash of
         the wrong one. Falls back to the OS preference, then to dark. --}}
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('theme');
                if (saved !== 'light' && saved !== 'dark') {
                    saved = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
                }
                document.documentElement.setAttribute('data-theme', saved);
            } catch (e) { /* private mode: keep the server-rendered default */ }
        })();
    </script>
    <title>{{ $metaTitle ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    <base href="{{ url('/') }}/">
    @vite(['resources/css/app.css'])
</head>

<body class="min-h-screen flex flex-col">
    <div class="grow py-5 bg-base-300">
        <div class="container mx-auto px-4">
            @include('layouts.partials.navbar')
            <div class="flex flex-col min-w-0 break-words bg-base-200 w-full mb-6 rounded-t-lg min-h-lvh">
                <div class="p-10 h-full">
                    <x-h1>@yield('title')</x-h1>
                    @yield('content')
                </div>
            </div>
            <div class="w-full relative -mt-6 z-10 flex justify-center">
                <div
                    class="bg-neutral border border-base-100 text-sm text-gray-400 px-6 py-4 rounded-b-xl shadow-md w-full flex justify-between items-center">
                    <div>
                        {{ config('app.name') }}
                    </div>
                    <div class="flex items-center gap-1">
                        <span>Loot the source on</span>
                        <a href="https://github.com/chadw/modern-allaclone" target="_blank"
                            class="flex items-center gap-1 link-accent link-hover">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" class="w-4 h-4">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path
                                    d="M9 19c-4.3 1.4 -4.3 -2.5 -6 -3m12 5v-3.5c0 -1 .1 -1.4 -.5 -2c2.8 -.3 5.5 -1.4 5.5 -6a4.6 4.6 0 0 0 -1.3 -3.2a4.2 4.2 0 0 0 -.1 -3.2s-1.1 -.3 -3.5 1.3a12.3 12.3 0 0 0 -6.2 0c-2.4 -1.6 -3.5 -1.3 -3.5 -1.3a4.2 4.2 0 0 0 -.1 3.2a4.6 4.6 0 0 0 -1.3 3.2c0 4.6 2.7 5.7 5.5 6c-.6 .6 -.6 1.2 -.5 2v3.5" />
                            </svg>
                            <span>GitHub</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @vite(['resources/js/app.js'])
    @stack('scripts')
    <div x-data="{ show: false }" x-init="window.addEventListener('scroll', () => show = window.scrollY > 200)" x-show="show" x-transition
        class="fixed bottom-6 right-6 z-50">
        <button @click="window.scrollTo({ top: 0, behavior: 'smooth' })"
            class="bg-base-200 text-base-content hover:bg-base-300 p-3 rounded-full shadow-lg" aria-label="Back to top">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
            </svg>
        </button>
    </div>
    <div x-data x-show="$store.tooltip.visible" x-html="$store.tooltip.content" x-ref="tooltip" x-transition x-cloak
        id="global-tooltip"
        class="fixed z-50 bg-base-200 rounded shadow-[0px_0px_15px_0px_rgba(0,_0,_0,_0.7)] max-w-lg text-sm pointer-events-none"
        style="position: absolute; display: none; top: 0; left: 0">
    </div>
</body>

</html>
