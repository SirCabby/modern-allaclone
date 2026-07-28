@extends('layouts.default')
@php
    $title = $npc->clean_name;
    if ($npc->level) {
        $title .= ' - Lvl ' . $npc->level . ($npc->maxlevel > 0 ? '-' . $npc->maxlevel : '');
    }
@endphp
@section('title')
    {{ $title }}
    <x-entity-id :id="$npc->id" label="NPC ID" />
@endsection

@section('content')
    @if ($npc)
        @php
            $npc_class = config('everquest.npc_class');
            $npc_race = config('everquest.db_races');
            $npc_body = config('everquest.db_bodytypes');
            $zone = $npc->firstSpawnEntries?->spawn2?->zoneData;
        @endphp
        <div class="flex flex-row items-center gap-2 flex-wrap">
            <span>
                {{ $npc_race[$npc->race] }} {{ $npc_class[$npc->class] }}
                <small class="text-accent">{{ $npc_body[$npc->bodytype] }}</small>
            </span>
            @if ($zone?->id)
                <a class="text-base link-info link-hover" href="{{ route('zones.show', $zone->id) }}"
                    title="{{ $zone->long_name }}">
                    {{ $zone->long_name }}
                </a>
            @endif
        </div>
        <div class="flex flex-row items-center gap-2 mb-4 flex-wrap">
            Primary Faction:
            @if ($npc->npcFaction?->primaryFaction)
                <a class="text-base link-secondary link-hover" title="{{ $npc->npcFaction->primaryFaction->name ?? 'None' }}"
                    href="{{ route('factions.show', $npc->npcFaction->primaryFaction->id) }}">
                    {{ $npc->npcFaction->primaryFaction->name ?? 'None' }}
                </a>
            @else
                None
            @endif
        </div>
        <div class="grid grid-cols-1 md:grid-cols-[3fr_1fr] gap-4">
            <div class="flex flex-col gap-2">
                <div class="stats stats-vertical lg:stats-horizontal shadow mb-3">
                    <div class="stat">
                        <div class="stat-figure">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path
                                    d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" />
                                <path d="M12 3v18" />
                                <path d="M3.5 12h17" />
                            </svg>
                        </div>
                        <div class="stat-title">AC</div>
                        <div class="stat-value">
                            @if (config('everquest.npc.display.ac'))
                                {{ number_format($npc->AC) }}
                            @else
                                <span class="italic text-base-content/50">Hidden</span>
                            @endif
                        </div>
                    </div>
                    <div class="stat">
                        <div class="stat-figure">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path
                                    d="M13 3a1 1 0 0 1 1 1v4.535l3.928 -2.267a1 1 0 0 1 1.366 .366l1 1.732a1 1 0 0 1 -.366 1.366l-3.927 2.268l3.927 2.269a1 1 0 0 1 .366 1.366l-1 1.732a1 1 0 0 1 -1.366 .366l-3.928 -2.269v4.536a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1v-4.536l-3.928 2.268a1 1 0 0 1 -1.366 -.366l-1 -1.732a1 1 0 0 1 .366 -1.366l3.927 -2.268l-3.927 -2.268a1 1 0 0 1 -.366 -1.366l1 -1.732a1 1 0 0 1 1.366 -.366l3.928 2.267v-4.535a1 1 0 0 1 1 -1h2z" />
                            </svg>
                        </div>
                        <div class="stat-title">HP</div>
                        <div class="stat-value">
                            @if (config('everquest.npc.display.hp'))
                                {{ number_format($npc->hp) }}
                            @else
                                <span class="italic text-base-content/50">Hidden</span>
                            @endif
                        </div>
                    </div>
                    <div class="stat">
                        <div class="stat-figure">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M6 21v-2a4 4 0 0 1 4 -4h2" />
                                <path
                                    d="M22 16c0 4 -2.5 6 -3.5 6s-3.5 -2 -3.5 -6c1 0 2.5 -.5 3.5 -1.5c1 1 2.5 1.5 3.5 1.5z" />
                                <path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" />
                            </svg>
                        </div>
                        <div class="stat-title">ATK</div>
                        <div class="stat-value">
                            @if (config('everquest.npc.display.atk'))
                                {{ $npc->ATK }}
                            @else
                                <span class="italic text-base-content/50">Hidden</span>
                            @endif
                        </div>
                    </div>
                    <div class="stat">
                        <div class="stat-figure">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M20 4v5l-9 7l-4 4l-3 -3l4 -4l7 -9z" />
                                <path d="M6.5 11.5l6 6" />
                            </svg>
                        </div>
                        <div class="stat-title">HIT</div>
                        <div class="stat-value">
                            @if (config('everquest.npc.display.dmg'))
                                {{ $npc->mindmg }}-{{ $npc->maxdmg }}
                            @else
                                <span class="italic text-base-content/50">Hidden</span>
                            @endif
                        </div>
                    </div>
                </div>
                @if (config('everquest.npc.display.resists'))
                    <div class="stats stats-vertical lg:stats-horizontal shadow mb-3">
                        <div class="stat w-full">
                            <div class="flex items-center w-full">
                                <div class="stat-title w-24 text-2xl">Resists</div>
                                <div class="grid grid-cols-6 gap-2 flex-1 text-center">
                                    <div>
                                        <div class="text-xs truncate text-violet-500">Magic</div>
                                        <div class="font-bold">{{ $npc->MR ?: 'Unknown' }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs truncate text-sky-500">Cold</div>
                                        <div class="font-bold">{{ $npc->CR ?: 'Unknown' }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs truncate text-red-500">Fire</div>
                                        <div class="font-bold">{{ $npc->FR ?: 'Unknown' }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs truncate text-green-600">Poison</div>
                                        <div class="font-bold">{{ $npc->PR ?: 'Unknown' }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs truncate text-lime-600">Disease</div>
                                        <div class="font-bold">{{ $npc->DR ?: 'Unknown' }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs truncate text-rose-600">Corrupt</div>
                                        <div class="font-bold">{{ $npc->Corrup ?: 'Unknown' }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
                @if ($npc->special_abilities && config('everquest.npc.display.abilities'))
                    <div class="stats stats-horizontal shadow">
                        <div class="stat w-full">
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                        <path d="M21 3v5l-11 9l-4 4l-3 -3l4 -4l9 -11z" />
                                        <path d="M5 13l6 6" />
                                        <path d="M14.32 17.32l3.68 3.68l3 -3l-3.365 -3.365" />
                                        <path d="M10 5.5l-2 -2.5h-5v5l3 2.5" />
                                    </svg>
                                </div>
                                <div class="flex flex-col">
                                    <div class="stat-title">Special Abilities</div>
                                    <div class="stat-value text-sm whitespace-normal">
                                        {{ implode(', ', $npc->parsed_special_abilities) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
            @if (config('everquest.npc.display.spells'))
                <div class="bg-base-200">
                    @if ($npc->attackProcSpell)
                        <h3 class="block mb-4 font-semibold">Proc ({{ $npc->attackProcSpellProcChance }}%)</h3>
                        <div class="flex items-center gap-2 mb-1 whitespace-nowrap">
                            <x-spell-link :spell_id="$npc->attackProcSpell->id" :spell_name="$npc->attackProcSpell->name" :spell_icon="$npc->attackProcSpell->new_icon" spell_class="flex" />
                        </div>
                        <div class="divider"></div>
                    @endif
                    <div class="grid grid-cols-1 gap-x-6 gap-y-4">
                        @if ($npc->filteredSpellEntries->isNotEmpty())
                            @foreach ($npc->filteredSpellEntries as $npcspell)
                                @if ($npcspell->spells)
                                    <div class="flex items-center gap-2 truncate">
                                        <x-spell-link :spell_id="$npcspell->spells->id" :spell_name="$npcspell->spells->name" :spell_icon="$npcspell->spells->new_icon"
                                            spell_class="flex" />
                                    </div>
                                @endif
                            @endforeach
                        @else
                            <p>No spells assigned.</p>
                        @endif
                    </div>
                </div>
            @else
                <div class="bg-base-200 text-center py-6 text-base-content/50">
                    <p>Spells hidden</p>
                </div>
            @endif
        </div>

        <div class="tabs tabs-lift mt-6">
            @if ($npc->lootTable?->loottableEntries)
                @include('npcs.partials.show.tab-drops')
            @endif
            @if ($npc->merchantlist->count() > 0)
                @include('npcs.partials.show.tab-merchant')
            @endif
            @if ($npc->spawnEntries && $npc->spawnEntries->count() > 0)
                @include('npcs.partials.show.tab-spawns')
            @endif
            @if ($questScripts->isNotEmpty())
                @include('npcs.partials.show.tab-quests')
            @endif
            @if ($raisesFaction || $lowersFaction)
                @include('npcs.partials.show.tab-faction')
            @endif
        </div>
    @endif
@endsection
