<input type="radio" name="npc_details" class="tab" aria-label="Spawns" {{ $defaultTab === 'spawns' ? 'checked' : '' }}/>
<div class="tab-content bg-base-100 border-base-300">
    <div class="border border-base-content/5 overflow-x-auto">
        <table class="table table-auto md:table-fixed w-full table-zebra">
            <thead class="text-xs uppercase bg-base-300">
                <tr>
                    <th scope="col" class="w-[20%]">Coords ({{ loc_order() }})</th>
                    <th scope="col" class="w-[50%]">Placeholders</th>
                    <th scope="col" class="w-[10%]">Chance</th>
                    <th scope="col" class="w-[20%]">Respawn</th>
                </tr>
            </thead>
            <tbody>
                @if ($npc->spawnEntries)
                    @foreach ($npc->spawnEntries as $spawn)
                        @if ($spawn->spawn2s->isEmpty())
                            @continue
                        @endif

                        @foreach ($spawn->spawn2s as $spawn2)
                            <tr>
                                <td scope="row">
                                    @if (config('everquest.npc.display.spawn_locs'))
                                        @if ($spawn2)
                                            {{ format_loc(floor($spawn2->x), floor($spawn2->y), floor($spawn2->z)) }}
                                        @else
                                            -
                                        @endif
                                    @else
                                        <span class="text-base-content/50">Hidden</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($spawn2->npcs)
                                        @foreach ($spawn2->npcs->where('id', '!=', $npc->id) as $phs)
                                            <a href="{{ route('npcs.show', $phs) }}" class="link-info link-hover">
                                                {{ $phs->clean_name }}
                                            </a>@if (!$loop->last),@endif
                                        @endforeach
                                    @else
                                        None
                                    @endif
                                </td>
                                <td>{{ $spawn->chance }}%</td>
                                <td>
                                    @if (config('everquest.npc.display.respawn'))
                                        @if ($spawn2)
                                            {{ seconds_to_human($spawn2->respawntime) }}
                                            @if ($spawn2->variance > 0)
                                            <span class="text-accent">+/- {{ seconds_to_human($spawn2->variance) }}</span>
                                            @endif
                                        @else
                                            Unknown
                                        @endif
                                    @else
                                        <span class="text-base-content/50">Hidden</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
</div>
