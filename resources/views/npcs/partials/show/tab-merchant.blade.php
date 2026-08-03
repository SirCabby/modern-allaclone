<input type="radio" name="npc_details" class="tab" aria-label="Merchant" {{ $defaultTab === 'merchant' ? 'checked' : '' }}/>
<div class="tab-content bg-base-100 border-base-300">
    <div class="border border-base-content/5 overflow-x-auto">
        <div class="w-full bg-neutral">
            <div class="flex font-bold text-accent/50 ml-3 p-2 items-center">
                @if ($npc->alt_currency_id)
                    @php
                        $currency = $altCurrency->firstWhere('id', $npc->alt_currency_id);
                    @endphp
                    <span class="mr-1">Items purchased with</span>
                    @if ($currency && $currency->item)
                        <x-item-link
                            :item_id="$currency->item->id"
                            :item_name="$currency->item->Name"
                            :item_icon="$currency->item->icon"
                            item_class="inline-flex"
                        />
                    @endif
                @elseif ($npc->class === 61)
                    <span>Items purchased with LDoN Currency</span>
                @elseif ($npc->class === 67 || $npc->class === 68)
                    <span>Items purchased with DoN Currency</span>
                @else
                    <span>Items purchasable from vendor</span>
                @endif
            </div>
        </div>
        <table class="table table-auto md:table-fixed w-full table-zebra">
            <thead class="text-xs uppercase bg-base-300">
                <tr>
                    <th scope="col" class="w-[60%]">Item</th>
                    <th scope="col" class="w-[20%]">Type</th>
                    <th scope="col" class="w-[20%] text-right">Cost</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($npc->merchantlist as $sell)
                    @if ($npc->class == 61 && $sell?->items?->ldonsold === 0)
                        @continue
                    @endif

                    @if(isUndiscoveredItem($sell?->items?->id, $discoveredItems))
                        <tr>
                            <td colspan="3">
                                <span class="text-warning">Undiscovered Item</span>
                            </td>
                        </tr>
                    @else
                    <tr>
                        <td scope="row">
                            <div class="flex flex-col">
                                @if ($sell->items)
                                <x-item-link
                                    :item_id="$sell?->items?->id"
                                    :item_name="$sell?->items?->Name"
                                    :item_icon="$sell?->items?->icon"
                                    item_class="inline-flex"
                                />
                                <span class="text-xs uppercase text-gray-500 ml-8 truncate">
                                    @if ($sell->items->slots > 0)
                                        {{ get_slots_string($sell->items->slots) }}
                                    @endif
                                    @if ($sell->items->bagslots > 0)
                                        <strong>Slots:</strong> {{ $sell->items->bagslots }}
                                        @if ($sell->items->bagwr > 0)
                                            <strong>WR:</strong> {{ $sell->items->bagwr }}%
                                        @endif
                                    @endif
                                </span>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div class="flex flex-col">
                                @if ($sell->items)
                                    {{-- Primary category, with anything the item
                                         also is underneath -- same rules as the
                                         item search Type column. --}}
                                    @php $categories = \App\Support\ItemCategories::labels($sell->items); @endphp
                                    {{ array_shift($categories) }}
                                    @if ($categories)
                                        <span class="text-xs text-gray-500 truncate">
                                            {{ implode(', ', $categories) }}
                                        </span>
                                    @endif
                                    {{-- augment --}}
                                    @if ($sell->items->itemtype == 54)
                                        @php
                                            $augSlots = [];

                                            if (($sell->items->augtype ?? 0) > 0) {
                                                $augType = $sell->items->augtype;
                                                for ($i = 1, $bit = 1; $i <= 24; $i++, $bit *= 2) {
                                                    if ($bit <= $augType && ($augType & $bit)) {
                                                        $augSlots[] = $i;
                                                    }
                                                }
                                                $slotsText = implode(', ', $augSlots);
                                            }
                                        @endphp
                                        @if (count($augSlots))
                                            <span class="text-xs text-gray-500 truncate">
                                                Type: {{ $slotsText }}
                                            </span>
                                        @endif
                                    @endif
                                @else
                                    <span class="text-gray-400 italic text-xs">Unknown item</span>
                                @endif
                            </div>
                        </td>
                        <td class="text-right">
                            @if ($sell->alt_currency_cost)
                                {{ $sell->alt_currency_cost }}
                            @elseif ($sell->items)
                                @if ($sell->items->pointtype === 1 && $sell->items->ldonsold !== 0)
                                    {{ $sell->items->ldonprice }} /
                                    {{ config('everquest.ldon_themes.' . $sell->items->ldontheme) }}
                                @elseif (in_array($sell->items->pointtype, [4, 5]))
                                    {{ $sell->items->ldonprice }}
                                @else
                                    {{ price($sell->items->price) }}
                                @endif
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
</div>
