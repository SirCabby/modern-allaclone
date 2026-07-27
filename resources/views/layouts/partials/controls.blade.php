@php
    use App\Support\ContentFilter;

    $currentEra = ContentFilter::currentExpansion();
    $serverEra = ContentFilter::serverExpansion();
    $pinned = session()->has(ContentFilter::SESSION_KEY);
@endphp

<div class="flex items-center gap-2 shrink-0">

    @if (config('everquest.allow_era_switch'))
        <form method="POST" action="{{ route('era.update') }}" class="flex items-center">
            @csrf
            <label class="sr-only" for="era-select">Era</label>
            <select id="era-select" name="era" onchange="this.form.submit()"
                class="select select-sm select-ghost w-auto max-w-[13rem]"
                title="{{ $pinned ? 'Pinned — the server is running ' . ContentFilter::label($serverEra) : 'Following the live server' }}">
                <option value="auto" @selected(!$pinned)>
                    Live &mdash; {{ ContentFilter::label($serverEra) }}
                </option>
                <option value="all" @selected($pinned && $currentEra === ContentFilter::ALL)>
                    All eras
                </option>
                @foreach (ContentFilter::availableExpansions() as $exp)
                    <option value="{{ $exp }}" @selected($pinned && $currentEra === (int) $exp)>
                        {{ ContentFilter::label((int) $exp) }}
                    </option>
                @endforeach
            </select>
        </form>
    @endif

    {{-- Theme toggle. The pre-paint script in the layout head owns the initial
         value; this only flips and persists it. --}}
    <button type="button" id="theme-toggle" class="btn btn-ghost btn-sm btn-square"
        aria-label="Toggle light and dark theme" title="Toggle light / dark">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden dark:block" data-theme-icon="dark"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
        </svg>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" data-theme-icon="light"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
        </svg>
    </button>
</div>

@once
    @push('scripts')
        <script>
            (function () {
                var btn = document.getElementById('theme-toggle');
                if (!btn) return;

                function paint(theme) {
                    document.documentElement.setAttribute('data-theme', theme);
                    var moon = btn.querySelector('[data-theme-icon="dark"]');
                    var sun = btn.querySelector('[data-theme-icon="light"]');
                    // Show the icon for the theme you would switch TO.
                    if (theme === 'dark') { moon.classList.add('hidden'); sun.classList.remove('hidden'); }
                    else { sun.classList.add('hidden'); moon.classList.remove('hidden'); }
                }

                paint(document.documentElement.getAttribute('data-theme') || 'dark');

                btn.addEventListener('click', function () {
                    var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                    try { localStorage.setItem('theme', next); } catch (e) {}
                    paint(next);
                });
            })();
        </script>
    @endpush
@endonce
