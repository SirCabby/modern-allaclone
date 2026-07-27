@extends('layouts.default')
@section('title', 'Zones')

@section('content')
    @if ($zones->isNotEmpty())
        {{-- Flat alphabetical list: the site shows the era the server is actually
             running, so there is no expansion for a visitor to pick between. --}}
        <p class="text-sm text-base-content/60 mb-4">
            {{ $zones->count() }} zones live in
            <span class="text-info">{{ \App\Support\ContentFilter::label(\App\Support\ContentFilter::currentExpansion()) }}</span>
        </p>
        <div class="card bg-base-300 shadow-sm mb-4">
            <div class="card-body">
                <ul class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                    @foreach ($zones as $val)
                        <li>
                            <a href="{{ route('zones.show', $val->id) }}{{ $val->version > 0 ? '?v=' . $val->version : '' }}"
                                class="block hover:bg-base-200 rounded p-2 transition">
                                <div class="text-base text-base-content">
                                    {{ $val->long_name }}
                                </div>
                                <div class="text-xs text-base-content/50 text-muted uppercase">
                                    {{ $val->short_name }}
                                    @if ($val->version > 0)
                                        <span class="text-accent">(v{{ $val->version }})</span>
                                    @endif
                                    @if ($val->zone_exp_multiplier)
                                        - <span class="text-primary">{{ $val->zone_exp_multiplier * 100 }}% exp</span>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @else
        <p class="text-base-content/60">No zones are live in this era.</p>
    @endif
@endsection
