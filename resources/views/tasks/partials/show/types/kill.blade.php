@if ($activity->goalcount > 0)
    <div class="flex flex-wrap items-center gap-1">
        Kill <span class="badge badge-sm badge-soft badge-accent">{{ $activity->goalcount }}x</span> of the following
        @if ($activity->cached_zones->isNotEmpty())
        in
            {!!
                $activity->cached_zones->map(function ($zone) {
                    return '<a href="' . route('zones.show', $zone->id) . '" class="link-accent link-hover">' .
                        $zone->long_name . '</a>';
                })->implode(', ')
            !!}
        @endif
    </div>
    @if ($activity->cached_npcs->isNotEmpty())
        <div class="flex flex-wrap items-center gap-1">
            @foreach ($activity->cached_npcs as $npc)
                <a href="{{ route('npcs.show', $npc->id) }}" class="link-info link-hover">
                    {{ $npc->clean_name }}
                </a>
                @php
                    $zone = null;
                    if ($activity->cached_zones->isEmpty()) {
                        $se = $npc['spawnentries']->first();
                        $s2 = $se?->spawn2;
                        if (is_object($s2) && method_exists($s2, 'first')) {
                            $s2 = $s2->first();
                        }

                        $zone = $s2?->zoneData;
                    }
                @endphp
                @if ($activity->cached_zones->isEmpty() && $zone)
                in
                <a href="{{ route('zones.show', $zone->id) }}" class="link-accent link-hover">
                    {{ $zone->long_name }}
                </a>
                @endif
                {{ $loop->last == true ? '' : ',' }}
            @endforeach
        </div>
    @endif
@endif
