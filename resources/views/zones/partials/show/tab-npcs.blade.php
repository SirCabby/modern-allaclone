<input type="radio" name="zone_details" class="tab" aria-label="NPCs ({{ count($npcs) ?? 0 }})" checked="checked" />
<div class="tab-content bg-base-100 border-base-300">
    <div class="border border-base-content/5 overflow-x-auto">
        <table class="table table-auto md:table-fixed w-full table-zebra" id="zone-npcs-table" data-sortable>
            <thead class="text-xs uppercase bg-base-300">
                <tr>
                    <th scope="col" class="w-[30%]" data-sort>Name</th>
                    <th scope="col" class="w-[10%]" data-sort="number">Lvl</th>
                    <th scope="col" class="w-[30%]" @if (config('everquest.npc.display.hp')) data-sort="number" @endif>HP</th>
                    <th scope="col" class="w-[10%] hidden md:table-cell truncate" title="Charmable" data-sort="number">Charmable</th>
                    <th scope="col" class="w-[10%] hidden md:table-cell" title="Rare" data-sort="number">Rare</th>
                    <th scope="col" class="w-[10%] hidden md:table-cell truncate" title="Raid Target" data-sort="number">Raid Tgt</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($npcs as $npc)
                    <tr>
                        <td scope="row" data-sort-value="{{ $npc->clean_name }}">
                            <div class="flex flex-col">
                                <a href="{{ route('npcs.show', $npc->id) }}"
                                    title="{{ $npc->clean_name }}"
                                    class="text-base link-info link-hover">{{ $npc->clean_name }}</a>
                                <span class="text-xs uppercase text-gray-500">
                                    {{ $npc_race[$npc->race] }} {{ $npc_class[$npc->class] }}
                                </span>
                            </div>
                        </td>
                        <td>{{ $npc->level }}</td>
                        <td class="text-nowrap">
                            @if (config('everquest.npc.display.hp'))
                                {{ number_format($npc->hp) }}
                            @else
                                <span class="text-base-content/50">Hidden</span>
                            @endif
                        </td>
                        <td class="hidden md:table-cell"
                            data-sort-value="{{ (int) !in_array('Uncharmable', $npc->parsed_special_abilities) }}">
                            {!! !in_array('Uncharmable', $npc->parsed_special_abilities)
                                ? '<span class="badge badge-soft badge-accent">Yes</span>'
                                : '<span class="badge badge-soft badge-warning">No</span>' !!}
                        </td>
                        <td class="hidden md:table-cell" data-sort-value="{{ (int) (bool) $npc->rare_spawn }}">
                            {!! $npc->rare_spawn
                                ? '<span class="badge badge-soft badge-accent">Yes</span>'
                                : '<span class="badge badge-soft badge-warning">No</span>' !!}
                        </td>
                        <td class="hidden md:table-cell" data-sort-value="{{ (int) (bool) $npc->raid_target }}">
                            {!! $npc->raid_target
                                ? '<span class="badge badge-soft badge-accent">Yes</span>'
                                : '<span class="badge badge-soft badge-warning">No</span>' !!}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
