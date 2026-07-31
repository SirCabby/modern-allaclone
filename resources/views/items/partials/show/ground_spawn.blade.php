<div class="flex w-full flex-col">
    <div class="divider">This item spawns on the ground</div>
</div>

<div class="max-h-96 scrollbar-thin scrollbar-thumb-accent scrollbar-track-base-300 overflow-y-auto">
    @foreach ($ground_spawn as $gs)
    <div class="px-1">
        <span class="block bg-neutral/80 text-sky-400 mt-2 p-2 font-bold sticky top-0">{{ $gs['zone_name'] }}</span>
    @if ($gs['spawns'])
    <ul role="list" class="list bg-base-300 divide-y divide-base-200">
        @foreach ($gs['spawns'] as $spawn)
        <li class="flex justify-between gap-x-6 px-3 py-1">
            <div class="flex min-w-0 gap-x-4">
                <div class="min-w-0 flex-auto">
                    <p class="text-sm/6 font-semibold text-neutral-content">
                        {{ format_loc($spawn['x'], $spawn['y'], $spawn['z'], labeled: true) }}
                    </p>
                </div>
            </div>
        </li>
        @endforeach
    </ul>
    @endif
    </div>
    @endforeach
</div>
