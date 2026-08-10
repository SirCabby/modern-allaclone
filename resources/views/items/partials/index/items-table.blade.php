@php
    $reserved = ['ac','hp','damage','delay','ratio'];
    $activeStats = array_values(array_filter([
        request('stat1'),
        request('stat2'),
        request('stat3'),
    ]));
    $activeStats = array_values(array_unique($activeStats));
    $statLabels = config('custom_search_fields.item_stats_select', []);

    // The columns the picker offers, in the order they appear in the table. A
    // stat the search is filtering on gets its own column further left, so it is
    // dropped from here rather than offered twice. Era and Zone both come out of
    // the era index and stay hidden until it has been built. Name is not here:
    // a row of nothing but numbers is not a result.
    $toggleableColumns = array_values(array_filter([
        ['key' => 'type', 'label' => 'Type'],
        !empty($eraOptions) ? ['key' => 'era', 'label' => 'Era'] : null,
        !empty($eraOptions) ? ['key' => 'zone', 'label' => 'Zone'] : null,
        ...array_map(
            fn ($column) => in_array($column['key'], $activeStats) ? null : $column,
            [
                ['key' => 'ac', 'label' => 'AC'],
                ['key' => 'hp', 'label' => 'HP'],
                ['key' => 'damage', 'label' => 'DMG'],
                ['key' => 'delay', 'label' => 'Delay'],
                ['key' => 'ratio', 'label' => 'Ratio'],
            ]
        ),
        ['key' => 'potency', 'label' => 'Potency'],
        ['key' => 'click', 'label' => 'Click'],
    ]));
@endphp

<div class="flex justify-end mb-2">
    <div class="relative" x-data="{ open: false }" @click.away="open = false" @keydown.escape="open = false">
        <button type="button" class="flex items-center gap-2 btn btn-sm btn-soft transition"
            @click="open = !open" :aria-expanded="open">
            <span>Columns</span>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition-transform duration-200"
                :class="{ 'rotate-180': open }"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <div x-show="open" x-cloak x-transition
            class="absolute right-0 top-full mt-1 z-50 w-52 p-3
                   bg-base-200 border border-base-content/50 rounded shadow-lg">
            <div class="mb-3">
                <button type="button" class="btn btn-xs btn-soft"
                    @click="$store.itemColumns.showAll()">All</button>
            </div>
            <div class="flex flex-col gap-1">
                @foreach ($toggleableColumns as $column)
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-sm shrink-0"
                            :checked="$store.itemColumns.visible('{{ $column['key'] }}')"
                            @change="$store.itemColumns.toggle('{{ $column['key'] }}')"
                        />
                        <span class="text-sm">{{ $column['label'] }}</span>
                    </label>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-gray-500">Remembered on this device</p>
        </div>
    </div>
</div>

{{-- x-effect rather than an x-show per cell: one pass over the table beats
     three hundred bindings, and clearing the inline style hands each cell back
     to the responsive classes it was rendered with. --}}
<div class="border border-base-content/5 overflow-x-auto" x-data x-effect="$store.itemColumns.apply($el)">
    <table class="table table-auto md:table-fixed w-full table-zebra">
        <thead class="text-xs uppercase bg-base-300">
            <tr>
                <th scope="col">@sortablelink('Name', 'Name')</th>
                <th scope="col" data-col="type" class="w-[20%]">@sortablelink('itemtype', 'Type')</th>
                @if (!empty($eraOptions))
                    <th scope="col" data-col="era" class="w-[10%] hidden lg:table-cell">@sortablelink('era', 'Era')</th>
                    <th scope="col" data-col="zone" class="w-[15%] hidden lg:table-cell">@sortablelink('zone', 'Zone')</th>
                @endif
                @foreach ($activeStats as $s)
                    <th scope="col" class="w-[5%]">@sortablelink($s, $statLabels[$s] ?? strtoupper($s))</th>
                @endforeach
                @if (!in_array('ac', $activeStats))
                    <th scope="col" data-col="ac" class="w-[5%] hidden md:table-cell">@sortablelink('ac', 'AC')</th>
                @endif
                @if (!in_array('hp', $activeStats))
                    <th scope="col" data-col="hp" class="w-[5%]">@sortablelink('hp', 'HP')</th>
                @endif
                @if (!in_array('damage', $activeStats))
                    <th scope="col" data-col="damage" class="w-[5%] hidden md:table-cell">@sortablelink('damage', 'DMG')</th>
                @endif
                @if (!in_array('delay', $activeStats))
                    <th scope="col" data-col="delay" class="w-[5%] hidden md:table-cell">@sortablelink('delay', 'Delay')</th>
                @endif
                @if (!in_array('ratio', $activeStats))
                    <th scope="col" data-col="ratio" class="w-[5%] hidden md:table-cell">@sortablelink('ratio', 'Ratio')</th>
                @endif
                <th scope="col" data-col="potency" class="w-[7%] hidden md:table-cell">@sortablelink('potency', 'Potency')</th>
                <th scope="col" data-col="click" class="w-[8%] hidden md:table-cell">@sortablelink('clicktype', 'Click')</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td scope="row">
                        <div class="flex flex-col">
                            <x-item-link
                                :item_id="$item->id"
                                :item_name="$item->Name"
                                :item_icon="$item->icon"
                                item_class="flex"
                            />
                            <span class="text-xs uppercase text-gray-500 ml-8 truncate">
                                @if ($item->slots > 0)
                                    {{ get_slots_string($item->slots) }}
                                @endif
                                @if ($item->bagslots > 0)
                                    <strong>Slots:</strong> {{ $item->bagslots }}
                                    @if ($item->bagwr > 0)
                                        <strong>WR:</strong> {{ $item->bagwr }}%
                                    @endif
                                @endif
                            </span>
                        </div>
                    </td>
                    <td data-col="type">
                        <div class="flex flex-col">
                            {{-- Every category the item answers to, not the one
                                 number itemtype has room for: the Guise of the
                                 Deceiver is a face-slot mask that calls itself
                                 1H Slashing, and Illusion is only ever a thing
                                 an item also does. Same rules the search just
                                 matched on. --}}
                            @php $categories = \App\Support\ItemCategories::labels($item); @endphp
                            {{ array_shift($categories) }}
                            @if ($categories)
                                <span class="text-xs text-gray-500 truncate">
                                    {{ implode(', ', $categories) }}
                                </span>
                            @endif
                            {{-- augment --}}
                            @if ($item->itemtype == 54)
                                @php
                                    $augSlots = [];

                                    if (($item->augtype ?? 0) > 0) {
                                        $augType = $item->augtype;

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
                            {{-- food/drink/alcohol strength --}}
                            @php $strength = consumable_strength_label($item->casttime_, $item->itemtype); @endphp
                            @if ($strength)
                                <span class="text-xs text-gray-500 truncate">{{ $strength }}</span>
                            @endif
                        </div>
                    </td>
                    @if (!empty($eraOptions))
                        @php
                            $era = $itemEras[$item->id] ?? null;
                            // The zone that dated the item -- where it drops, is
                            // sold, or is handed over. Crafted and LDoN-flagged
                            // items are dated without a place, so they have none.
                            $eraZone = App\Models\Zone::forEra($eraZones, $era?->zone, $era?->expansion);
                        @endphp
                        <td data-col="era" class="hidden lg:table-cell text-sm"
                            @if ($era) title="Earliest source: {{ App\Models\ItemExpansion::sourceLabel($era->source) }}" @endif>
                            {{ $era ? App\Support\ContentFilter::label($era->expansion) : '-' }}
                        </td>
                        <td data-col="zone" class="hidden lg:table-cell text-sm"
                            @if ($era) title="{{ ucfirst(App\Models\ItemExpansion::sourceLabel($era->source)) }}" @endif>
                            @if ($eraZone)
                                <a href="{{ route('zones.show', $eraZone->id) }}"
                                    class="link-accent link-hover">{{ $eraZone->long_name }}</a>
                            @else
                                -
                            @endif
                        </td>
                    @endif
                    @foreach ($activeStats as $s)
                        @php
                            $val = null;
                            if ($s === 'ratio') {
                                $val = ($item->damage > 0 && $item->delay > 0) ? number_format($item->delay / $item->damage, 2) : '-';
                            } else {
                                $val = data_get($item, $s, null);
                            }
                        @endphp
                        <td class="text-sm">{{ $val === null || $val === '' ? '-' : $val }}</td>
                    @endforeach
                    @if (!in_array('ac', $activeStats))
                        <td data-col="ac" class="hidden md:table-cell">{{ $item->ac ?? '-' }}</td>
                    @endif
                    @if (!in_array('hp', $activeStats))
                        <td data-col="hp">{{ $item->hp }}</td>
                    @endif
                    @if (!in_array('damage', $activeStats))
                        <td data-col="damage" class="hidden md:table-cell">{{ $item->damage }}</td>
                    @endif
                    @if (!in_array('delay', $activeStats))
                        <td data-col="delay" class="hidden md:table-cell">{{ $item->delay }}</td>
                    @endif
                    @if (!in_array('ratio', $activeStats))
                        <td data-col="ratio" class="hidden md:table-cell">
                            {{ $item->damage > 0 ? number_format($item->delay / $item->damage, 2) : '-' }}
                        </td>
                    @endif
                    {{-- The number, so the column sorts and reads as one; the
                         wording it maps to ("Feast", "Flowing Drink") is on
                         hover and spelled out under Type. --}}
                    @php $potency = consumable_strength_label($item->casttime_, $item->itemtype); @endphp
                    <td data-col="potency" class="hidden md:table-cell"
                        @if ($potency) title="{{ $potency }}" @endif>
                        {{ $potency ? $item->casttime_ : '-' }}
                    </td>
                    {{-- Where the click fires from, which is the only thing
                         separating the Guise of the Deceiver from the Mask of
                         Deception. Blank for the items that have no click. --}}
                    @php $clickUsage = click_usage($item->clickeffect, $item->clicktype); @endphp
                    <td data-col="click" class="hidden md:table-cell"
                        @if ($clickUsage) title="{{ config('everquest.click_type_hints.' . $clickUsage) }}" @endif>
                        {{ $clickUsage ? config('everquest.click_type_labels.' . $clickUsage) : '-' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- Runs before the table above paints, so hidden columns never flash into view
     on load or on every paginate. Alpine boots later and takes over from here;
     it reads the same key. --}}
<script>
    (function () {
        try {
            var hidden = JSON.parse(localStorage.getItem('item_columns_hidden')) || [];

            if (!hidden.length) {
                return;
            }

            document.querySelectorAll('[data-col]').forEach(function (cell) {
                if (hidden.indexOf(cell.dataset.col) !== -1) {
                    cell.style.display = 'none';
                }
            });
        } catch (e) {
            // A corrupt or unreadable entry just means every column shows.
        }
    })();
</script>

{{ $items->onEachSide(2)->links() }}
