<input type="radio" name="npc_details" class="tab" aria-label="Drops" {{ $defaultTab === 'drops' ? 'checked' : '' }}/>
<div class="tab-content bg-base-100 border-base-300">
    @foreach ($npc->lootTable->loottableEntries as $drop)
        <div class="border border-base-content/5 overflow-x-auto {{ !$loop->last ? 'mb-6' : '' }}">
            <div class="w-full bg-neutral">
                <p class="font-bold text-accent/50 ml-3 p-2">
                    With a probability of {{ floor($drop->probability) }}% (multiplier: {{ $drop->multiplier }})
                    -
                    {{ $drop->mindrop == $drop->droplimit
                        ? $drop->droplimit . ' drop' . ($drop->droplimit > 1 ? 's' : '')
                        : $drop->mindrop . '-' . $drop->droplimit . ' drops' }}
                </p>
            </div>
            <table class="table table-auto md:table-fixed w-full table-zebra">
                <thead class="text-xs uppercase bg-base-300">
                    <tr>
                        <th scope="col" class="w-[80%]">Item</th>
                        <th scope="col" class="w-[20%]">Chance</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($drop->lootdropEntries)
                        @foreach ($drop->lootdropEntries as $loot)
                            {{-- Loot tables outlive the items they point at: an item
                                 dropped from `items` leaves its lootdrop entry behind.
                                 That row is not undiscovered, it is unnameable, so it
                                 falls through to the "Unknown item" cell below with
                                 its drop chance intact. --}}
                            @if($loot->item && isUndiscoveredItem($loot->item->id, $discoveredItems))
                                <tr>
                                    <td colspan="2">
                                        <span class="text-warning">Undiscovered Item</span>
                                    </td>
                                </tr>
                            @else
                            <tr>
                                <td scope="row">
                                    <div class="flex items-center space-x-3">
                                        @if ($loot->item)
                                            <x-item-link
                                                :item_id="$loot->item->id"
                                                :item_name="$loot->item->Name"
                                                :item_icon="$loot->item->icon"
                                            />
                                        @else
                                            <span class="text-gray-400 italic text-xs">Unknown item</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    @if (config('everquest.npc.display.loot_chance'))
                                        {{ $loot->chance }}% ({{ ($loot->chance * $drop->probability) / 100 }}% Global)
                                    @else
                                        <span class="text-base-content/50">Hidden</span>
                                    @endif
                                </td>
                            </tr>
                            @endif
                        @endforeach
                    @endif
                </tbody>
            </table>
        </div>
    @endforeach
</div>
