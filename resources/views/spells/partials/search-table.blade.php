<div class="flex w-full flex-col my-6">
    <div class="divider uppercase text-xl font-bold text-sky-400">
        {{ $title ?? 'Spells' }}
        @if($groupedSpells->count() > 1 || !is_null($groupedSpells->first()['level'] ?? null))
            ({{ $groupedSpells->sum(fn($group) => count($group['spells'])) }} Found)
        @endif
    </div>
</div>

@foreach ($groupedSpells as $spellGroup)
    @if (!is_null($spellGroup['level']))
        <span
            x-data="spellLevelSticky" x-show="show" x-transition
            class="block bg-neutral/80 text-sky-400 mt-5 p-2 font-bold sticky top-15 z-10">
            Level: {{ $spellGroup['level'] }}
        </span>
    @endif
    <div class="border border-base-content/5 overflow-x-auto">
        {{-- One table per level group, so a sort reorders the spells within a
             level rather than collapsing the levels together. --}}
        <table class="table table-auto md:table-fixed w-full table-zebra" data-sortable>
            <thead class="text-xs uppercase bg-base-300">
                <tr>
                    <th scope="col" class="w-[20%]" data-sort>Name</th>
                    <th scope="col" class="w-[10%]" data-sort>Class</th>
                    <th scope="col" class="w-[40%]">Effect(s)</th>
                    <th scope="col" class="w-[10%] hidden lg:table-cell" data-sort="number">Mana</th>
                    <th scope="col" class="w-[10%] hidden md:table-cell" data-sort>Skill</th>
                    <th scope="col" class="w-[10%] hidden lg:table-cell" data-sort>Target Type</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($spellGroup['spells'] as $sp)
                    <tr>
                        <td scope="row" data-sort-value="{{ $sp->name }}">
                            <x-spell-link
                                :spell_id="$sp->id"
                                :spell_name="$sp->name"
                                :spell_icon="$sp->new_icon"
                                spell_class="flex text-base"
                            />
                        </td>
                        <td>
                            @if (!empty($selectedClass))
                                {{ config('everquest.classes_abbr.' . $selectedClass) }}/{{ $spellGroup['level'] }}
                            @else
                                @php
                                    $classLevels = [];
                                    $maxLevel = config('everquest.server_max_level');

                                    for ($i = 1; $i <= 16; $i++) {
                                        $val = $sp->{"classes{$i}"} ?? null;
                                        if (is_numeric($val) && $val > 0 && $val < 255) {
                                            $abbr = config("everquest.classes_abbr.{$i}");
                                            $classLevels[] = $val <= $maxLevel ? "{$abbr}/{$val}" : $abbr;
                                        }
                                    }
                                @endphp
                                {{ $classLevels ? implode(', ', $classLevels) : '—' }}
                            @endif
                        </td>
                        <td class="text-nowrap">
                            @for ($n = 1; $n <= 12; $n++)
                            <x-spell-effect
                                :spell="$sp"
                                :n="$n"
                                :all-spells="$allSpells"
                                :all-zones="$allZones"
                            />
                            @endfor
                        </td>
                        <td class="hidden lg:table-cell">{{ $sp->mana }}</td>
                        <td class="hidden md:table-cell">
                            {{ config('everquest.db_skills.' . $sp->skill) ?? 'Unknown' }}
                        </td>
                        <td class="hidden lg:table-cell">
                            {{ config('everquest.spell_targets.' . $sp->targettype) ?? '-' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endforeach
