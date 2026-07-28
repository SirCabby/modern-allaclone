@extends('layouts.default')
@section('title')
    Pet - {{ $pet->type ?? 'Unknown' }}
    <x-entity-id :id="$pet->id" label="Pet ID" />
@endsection

@section('content')
    @if ($pet && $pet->npcs)
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
                        <div class="stat-value">{{ number_format($pet->npcs->AC) }}</div>
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
                        <div class="stat-value">{{ number_format($pet->npcs->hp) }}</div>
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
                        <div class="stat-value">{{ $pet->npcs->ATK }}</div>
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
                        <div class="stat-value">{{ $pet->npcs->mindmg }}-{{ $pet->npcs->maxdmg }}</div>
                    </div>
                </div>
                @if ($pet->npcs->special_abilities)
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
                                        {{ implode(', ', $pet->npcs->parsed_special_abilities) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
            <div class="bg-base-200">
                @if ($pet->attackProcSpell)
                    <h3 class="block mb-4 font-semibold">Proc ({{ $pet->attackProcSpellProcChance }}%)</h3>
                    <div class="flex items-center gap-2 mb-1 whitespace-nowrap">
                        <x-spell-link
                            :spell_id="$pet->attackProcSpell->id"
                            :spell_name="$pet->attackProcSpell->name"
                            :spell_icon="$pet->attackProcSpell->new_icon"
                            spell_class="flex"
                        />
                    </div>
                    <div class="divider"></div>
                @endif
                <div class="grid grid-cols-1 gap-x-6 gap-y-4">
                    @if ($pet->filteredSpellEntries->isNotEmpty())
                        @foreach ($pet->filteredSpellEntries as $npcspell)
                            @if ($npcspell->spells)
                                <div class="flex items-center gap-2 truncate">
                                    <x-spell-link
                                        :spell_id="$npcspell->spells->id"
                                        :spell_name="$npcspell->spells->name"
                                        :spell_icon="$npcspell->spells->new_icon"
                                        spell_class="flex"
                                    />
                                </div>
                            @endif
                        @endforeach
                    @else
                        <p>No spells assigned.</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="divider"></div>
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-4 divide-y divide-base-content/5">
            <div class="grid grid-cols-3 gap-x-2">
                <dt class="text-sm font-medium col-span-1">Race</dt>
                <dd class="text-sm col-span-2 text-right">
                    {{ config('everquest.db_races.' . $pet->npcs->race) ?? 'Unknown' }}
                </dd>
            </div>
            <div class="grid grid-cols-3 gap-x-2">
                <dt class="text-sm font-medium col-span-1">Class</dt>
                <dd class="text-sm col-span-2 text-right">{{ config('everquest.classes.' . $pet->npcs->class) }}</dd>
            </div>
            <div class="grid grid-cols-3 gap-x-2">
                <dt class="text-sm font-medium col-span-1">Size</dt>
                <dd class="text-sm col-span-2 text-right">{{ $pet->npcs->size }}</dd>
            </div>
            <div class="grid grid-cols-3 gap-x-2">
                <dt class="text-sm font-medium col-span-1">Regen</dt>
                <dd class="text-sm col-span-2 text-right">{{ $pet->npcs->hp_regen_rate }}/tick</dd>
            </div>
        </dl>
        <div class="divider"></div>
        <div class="grid sm:grid-cols-2 gap-8">
            <div>
                <table class="w-full table-zebra">
                    <tr class="sm:hidden table-row">
                        <td colspan="2" class="border-b border-base-content/5 text-base-content">Stats</td>
                    </tr>
                    <x-item-stat name="STR" :stat="$pet->npcs->STR" :stat2="null" />
                    <x-item-stat name="STA" :stat="$pet->npcs->STA" :stat2="null" />
                    <x-item-stat name="INT" :stat="$pet->npcs->_INT" :stat2="null" />
                    <x-item-stat name="WIS" :stat="$pet->npcs->WIS" :stat2="null" />
                    <x-item-stat name="AGI" :stat="$pet->npcs->AGI" :stat2="null" />
                    <x-item-stat name="DEX" :stat="$pet->npcs->DEX" :stat2="null" />
                    <x-item-stat name="CHA" :stat="$pet->npcs->CHA" :stat2="null" />
                </table>
            </div>
            <div>
                <table class="w-full table-zebra">
                    <tr class="sm:hidden table-row">
                        <td colspan="2" class="border-b border-base-content/5 text-base-content">Resists</td>
                    </tr>
                    <x-item-stat name="MR" :stat="$pet->npcs->MR" :stat2="null" />
                    <x-item-stat name="FR" :stat="$pet->npcs->FR" :stat2="null" />
                    <x-item-stat name="CR" :stat="$pet->npcs->CR" :stat2="null" />
                    <x-item-stat name="DR" :stat="$pet->npcs->DR" :stat2="null" />
                    <x-item-stat name="PR" :stat="$pet->npcs->PR" :stat2="null" />
                </table>
            </div>
        </div>
    @endif
@endsection
