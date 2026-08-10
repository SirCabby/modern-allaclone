<h2 class="text-lg font-semibold mb-2">NPCs you can kill to <span class="text-success uppercase">raise</span> the
    faction</h2>
<div class="border border-base-content/5 overflow-x-auto">
    @if ($factions['raised'])
        {{-- The zone headings are separator rows, so a sort reorders the NPCs
             inside each zone rather than shuffling them between zones. --}}
        <table class="table table-auto md:table-fixed table-sm w-full table-zebra" data-sortable>
            <thead class="text-xs uppercase bg-base-300">
                <tr>
                    <th scope="col" class="w-[90%]" data-sort>NPC</th>
                    <th scope="col" class="w-[10%] text-right" data-sort="number">Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($factions['raised'] as $zone => $raised)
                    @php
                        [$zoneId, $zoneName] = explode('|', $zone);
                    @endphp
                    @if ($zoneId)
                        <tr data-sort-group>
                            <td colspan="2" class="bg-neutral-900">
                                NPCs In
                                <a href="{{ route('zones.show', $zoneId) }}" class="text-base font-semibold link-accent link-hover">
                                    {{ $zoneName }}
                                </a>
                            </td>
                        </tr>
                        @foreach ($raised as $raise)
                            <tr>
                                <td scope="row">
                                    <a href="{{ route('npcs.show', $raise['npc_id']) }}"
                                        class="text-base link-info link-hover">
                                        {{ $raise['npc_name'] }}
                                    </a>
                                </td>
                                <td class="text-success text-right whitespace-nowrap">
                                    +{{ $raise['value'] }}
                                </td>
                            </tr>
                        @endforeach
                    @endif
                @endforeach
            </tbody>
        </table>
    @endif
</div>
