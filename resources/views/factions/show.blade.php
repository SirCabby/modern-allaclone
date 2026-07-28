@extends('layouts.default')
@section('title')
    Faction - {{ $faction->name ?? 'Faction' }}
    <x-entity-id :id="$faction->id" label="Faction ID" />
@endsection

@section('content')
    @if ($allFactions->isNotEmpty())
        <label class="select mb-6">
            <span class="label">Factions</span>
            <select id="select-faction">
                @foreach ($allFactions as $val)
                    <option value="{{ $val->id }}" {{ $val->id == $faction->id ? 'selected' : '' }}>{{ $val->name }}</option>
                @endforeach
            </select>
        </label>
    @endif

    @if ($factions)
        <div class="flex flex-col md:flex-row gap-4">
            <div class="w-full md:w-1/2">
                @include('factions.partials.show.raised')
            </div>

            <div class="w-full md:w-1/2">
                @include('factions.partials.show.lowered')
            </div>
        </div>
    @endif
@endsection
