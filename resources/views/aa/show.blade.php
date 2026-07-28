@extends('layouts.default')
@section('title')
    AA: {{ $ability->name }}
    <x-entity-id :id="$ability->id" label="AA ID" />
@endsection


@section('content')

    @include('aa.partials.filters')

    <div class="divider"></div>

    <div data-spa-defs='@json(config('eqemu_spa_defs'))'>
        <div class="space-y-4">
            <div class="card bg-base-200">
                <div class="flex items-start gap-6">
                    <div class="flex-1">
                        <div class="flex items-start justify-between">
                            <div>
                                <h2 class="text-2xl font-bold">{{ $ability->name }}</h2>
                                <div class="text-sm text-base-content/60">ID: {{ $ability->id }}</div>
                                <div class="text-sm text-base-content/60">
                                    Category: {{ config('everquest.aa_categories')[$ability->category] ?? 'Unknown' }} /
                                    Type: {{ config('everquest.aa_types')[$ability->type] ?? 'Unknown' }}
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 text-sm text-base-content">
                            @if ($ability->charges >= 1)
                                <div class="mb-2">
                                    <strong class="mr-2">Charges:</strong>
                                    <div class="inline-flex flex-wrap gap-1 items-center">
                                        <span class="badge badge-sm badge-soft">{{ $ability->charges }}</span>
                                    </div>
                                </div>
                            @endif
                            <div class="mb-2">
                                <strong class="mr-2">Classes:</strong>
                                @php
                                    $mask = (int) $ability->classes;
                                    $classMap = config('everquest.classes_bit_abbr', []);
                                    $classList = [];
                                    foreach ($classMap as $bit => $abbr) {
                                        if (($mask & (int) $bit) !== 0) {
                                            $classList[] = $abbr;
                                        }
                                    }
                                @endphp

                                @if (count($classList))
                                    <div class="inline-flex flex-wrap gap-1 items-center">
                                        @foreach ($classList as $abbr)
                                            <span class="badge badge-sm badge-soft">{{ $abbr }}</span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-sm text-base-content/60">All</span>
                                @endif
                            </div>

                            <div>
                                <strong class="mr-2">Races:</strong>
                                @php
                                    $rMask = (int) $ability->races;
                                    $raceMap = config('everquest.races_bit', []);
                                    $raceList = [];
                                    foreach ($raceMap as $bit => $name) {
                                        if (($rMask & (int) $bit) !== 0) {
                                            $raceList[] = $name;
                                        }
                                    }
                                @endphp

                                @if (count($raceList))
                                    <div class="inline-flex flex-wrap gap-1 items-center">
                                        @foreach ($raceList as $r)
                                            <span class="badge badge-sm badge-soft">{{ $r }}</span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-sm text-base-content/60">All</span>
                                @endif
                            </div>
                        </div>

                        <div class="mt-2 text-sm">{{ $ability->description ?? '' }}</div>
                    </div>
                </div>
            </div>

            <div class="card bg-base-200">
                <h3 class="font-bold text-lg mb-3 divider divider-start">
                    Ranks ({{ $rankCount ?? count($allRanks) }} total)
                </h3>
                <div class="border border-base-content/5 overflow-x-auto">
                    <table class="table table-auto md:table-fixed w-full table-zebra">
                        <thead class="text-xs uppercase bg-base-300">
                            <tr>
                                <th class="w-[5%]">#</th>
                                <th class="w-[5%]">Cost</th>
                                <th class="w-[5%]">Level</th>
                                <th>Prereqs</th>
                                <th>Spell</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($allRanks as $rank)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ data_get($rank, 'cost', '-') }}</td>
                                    <td>{{ data_get($rank, 'level_req', '-') }}</td>
                                    <td>
                                        @php
                                            $prereqs = data_get($rank, 'prereqs', []);
                                            $pCount = is_countable($prereqs) ? count($prereqs) : 0;
                                            $prereqTexts = [];
                                            foreach ($prereqs as $p) {
                                                $pname = data_get($p, 'ability.name') ?? data_get($p, 'name') ?? ('AA #' . (data_get($p, 'aa_id') ?? '??'));
                                                $ppoints = data_get($p, 'points') ?? '-';
                                                $prereqTexts[] = $pname . ' (' . $ppoints . ' pts)';
                                            }
                                        @endphp

                                        @if ($pCount > 0)
                                            <div class="mt-1 text-xs text-base-content/60">{{ implode(', ', $prereqTexts) }}</div>
                                        @else
                                            <div class="font-medium">{{ $pCount }}</div>
                                        @endif
                                    </td>
                                    <td class="truncate">
                                        @if (data_get($rank, 'spell_.id'))
                                            <x-spell-link
                                                :spell_id="data_get($rank, 'spell_.id')"
                                                :spell_name="data_get($rank, 'spell_.name')"
                                                :spell_icon="data_get($rank, 'spell_.new_icon')"
                                                spell_class="flex text-base" effects-only="true"
                                            />
                                        @else
                                            <span class="text-sm text-base-content/60">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

@endsection
