<input type="radio" name="zone_details" class="tab" aria-label="Drops ({{ count($drops) ?? 0 }})" />
<div class="tab-content bg-base-100 border-base-300">
    <div class="border border-base-content/5 overflow-x-auto">
        <table class="table table-auto md:table-fixed w-full table-zebra">
            <thead class="text-xs uppercase bg-base-300">
                <tr>
                    <th scope="col" class="w-2/5">Item</th>
                    <th scope="col" class="w-1/5 hidden md:table-cell">Type</th>
                    <th scope="col" class="w-2/5">Dropped</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($drops as $drop)
                    @if(isUndiscoveredItem($drop['item']['id'], $discoveredItems))
                        <tr>
                            <td colspan="3">
                                <span class="text-warning">Undiscovered Item</span>
                            </td>
                        </tr>
                    @else
                    <tr>
                        <td scope="row">
                            <div class="flex items-center space-x-3">
                                <x-item-link
                                    :item_id="$drop['item']['id']"
                                    :item_name="$drop['item']['Name']"
                                    :item_icon="$drop['item']['icon']"
                                />
                            </div>
                        </td>
                        <td class="hidden md:table-cell">
                            @if ($drop['item']['bagslots'] > 0)
                                Bag
                            @else
                                {{ implode(', ', \App\Support\ItemCategories::labels($drop['item'])) }}
                            @endif
                        </td>
                        <td class="text-nowrap">
                            @if (count($drop['npcs']) === 1)
                                {{ $drop['npcs'][0]->clean_name }} (Lvl {{ $drop['npcs'][0]->level }})
                            @elseif (count($drop['npcs']) > 1)
                            <div class="zone-npc-drops collapse collapse-arrow bg-ghost">
                                <input type="checkbox" />
                                <div class="collapse-title font-medium p-0">
                                    {{ $drop['npcs'][0]->clean_name }} (Lvl {{ $drop['npcs'][0]->level }})
                                    + {{ count(array_slice($drop['npcs'], 1)) }} more
                                </div>
                                <div class="collapse-content">
                                    <ul class="list-none list-inside">
                                        @foreach (array_slice($drop['npcs'], 1) as $npc)
                                            <li>{{ $npc->clean_name }} (Lvl {{ $npc->level }})</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                            @endif
                        </td>
                    </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
</div>
