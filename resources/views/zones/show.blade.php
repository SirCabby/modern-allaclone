@extends('layouts.default')

@php
    $title = $zone->long_name . ' (' . $zone->short_name . ')';
    if ($zone->version) {
        $title .= ' - v' . $zone->version;
    }
@endphp
@section('title')
    {{ $title ?? 'Zone' }}
    <x-entity-id :id="$zone->zoneidnumber" label="Zone ID" />
@endsection

@section('content')
    @if ($zone)

        @php
            $npc_class = config('everquest.npc_class');
            $npc_race = config('everquest.db_races');
            $item_type = config('everquest.item_types');
        @endphp

        <div class="card mb-6">
            <div class="card-body p-0">
                <div class="flex flex-wrap gap-4">
                    <div class="flex flex-col">
                        @if ($zone->canbind)
                            <div class="badge badge-soft badge-success">Bind</div>
                        @else
                            <div class="badge badge-soft badge-error">Bind</div>
                        @endif
                    </div>
                    <div class="flex flex-col">
                        @if ($zone->canlevitate)
                            <div class="badge badge-soft badge-success">Levitate</div>
                        @else
                            <div class="badge badge-soft badge-error">Levitate</div>
                        @endif
                    </div>
                    <div class="flex flex-col">
                        @if ($zone->castoutdoor)
                            <div class="badge badge-soft badge-success">Outdoor</div>
                        @else
                            <div class="badge badge-soft badge-error">Outdoor</div>
                        @endif
                    </div>
                    <div class="flex flex-col">
                        @if ($zone->hotzone)
                            <div class="badge badge-soft badge-success">Hotzone</div>
                        @else
                            <div class="badge badge-soft badge-error">Hotzone</div>
                        @endif
                    </div>
                </div>
                <div class="flex flex-wrap gap-4 mt-2">
                    <div class="flex flex-col">
                        <span>
                            <strong>Succor:</strong>
                            {{ format_loc($zone->safe_x ?? '?', $zone->safe_y ?? '?', $zone->safe_z ?? '?', labeled: true) }}
                        </span>
                    </div>
                    <div class="flex flex-col whitespace-nowrap">
                        <span>
                            <strong>Exp Multi:</strong> <span
                                class="text-accent">{{ $zone->zone_exp_multiplier * 100 }}%</span>
                        </span>
                    </div>
                    @if ($connectedZones->isNotEmpty())
                        <div class="flex flex-col">
                            <p class="text-sm text-base-content">
                                <strong>Connected Zones:</strong>
                                @foreach ($connectedZones as $i => $connectedZone)
                                    <a href="{{ route('zones.show', $connectedZone->id) }}"
                                        title="{{ $connectedZone->long_name }}"
                                        class="link link-hover link-info">
                                        {{ $connectedZone->long_name }}
                                    </a>{{ !$loop->last ? ',' : '' }}
                                @endforeach
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="tabs tabs-lift">
            @if ($npcs->isNotEmpty())
                @include('zones.partials.show.tab-npcs')
            @endif
            @if ($drops)
                @include('zones.partials.show.tab-drops')
            @endif
            @if ($spawnGroups->isNotEmpty())
                @include('zones.partials.show.tab-spawngroups')
            @endif
            @if ($foraged->isNotEmpty())
                @include('zones.partials.show.tab-foraged')
            @endif
            @if ($fished->isNotEmpty())
                @include('zones.partials.show.tab-fished')
            @endif
            @if ($tasks->isNotEmpty() && config('everquest.tasks.enable'))
                @include('zones.partials.show.tab-tasks')
            @endif
        </div>
    @endif
@endsection
