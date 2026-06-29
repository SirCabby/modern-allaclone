<input type="radio" name="zone_details" class="tab" aria-label="Spawns Locs ({{ count($spawnGroups) ?? 0 }})" />
<div class="tab-content bg-base-100 border-base-300">
    <div class="border border-base-content/5 overflow-x-auto">
        <table class="table table-auto md:table-fixed w-full table-zebra">
            <thead class="text-xs uppercase bg-base-300">
                <tr>
                    <th scope="col" class="w-[40%] hidden md:table-cell">
                        @if (config('everquest.coords_as_yxz'))
                            Spawn Grp (y,x,z)
                        @else
                            Spawn Grp (x,y,z)
                        @endif
                    </th>
                    <th scope="col" class="w-[40%]">NPCs</th>
                    <th scope="col" class="w-[20%] text-right">Respawn</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($spawnGroups as $grp)
                    <tr>
                        <td scope="row" class="hidden md:table-cell">
                            <div class="flex flex-col">
                                {{ $grp->name }}
                                <span class="text-xs uppercase text-gray-500">
                                    @if (config('everquest.npc.display.spawn_locs'))
                                        @if (config('everquest.coords_as_yxz'))
                                            {{ floor($grp->y) }},
                                            {{ floor($grp->x) }},
                                            {{ floor($grp->z) }}
                                        @else
                                            {{ floor($grp->x) }},
                                            {{ floor($grp->y) }},
                                            {{ floor($grp->z) }}
                                        @endif
                                    @else
                                        Hidden
                                    @endif
                                </span>
                            </div>
                        </td>
                        <td>
                            <ul class="list">
                                @foreach ($grp->spawnentries as $spawn)
                                    @if ($spawn->npc)
                                        <li>
                                            <a class="text-base link-info link-hover"
                                                title="{{ $spawn->npc->clean_name }}"
                                                href="{{ route('npcs.show', $spawn->npc->id) }}">{{ $spawn->npc->clean_name }}</a>
                                            (lvl {{ $spawn->npc->level }}), {{ $spawn->chance }}% chance
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </td>
                        <td class="text-nowrap text-right">
                            @if (config('everquest.npc.display.respawn'))
                                {{ seconds_to_human($grp->respawntime) }}
                            @else
                                <span class="text-base-content/50">Hidden</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
