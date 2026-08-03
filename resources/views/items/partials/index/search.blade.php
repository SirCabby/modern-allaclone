@php
    use App\Support\ContentFilter;

    // slots
    $removeSlots = ['65536', '32768', '1024', '512', '16', '2'];

    $stats = config('custom_search_fields.item_stats_select');

    // Ticked eras come back as strings on the query string.
    $selectedEras = array_map('intval', (array) request('expansion', []));

    // Item types. `+=` rather than collapse() so the numeric type ids survive
    // the flattening -- array_merge would renumber them.
    $typeGroups = config('custom_search_fields.item_types_select');
    $typeLabels = [];
    foreach ($typeGroups as $types) {
        $typeLabels += $types;
    }

    // Compared as strings throughout: that is what the query string hands back,
    // and what the checkbox values are. Bookmarks from when this was a single
    // select still arrive as a scalar, hence the cast.
    $selectedTypes = collect((array) request('type', []))
        ->filter(fn ($type) => is_numeric($type) && isset($typeLabels[(int) $type]))
        ->map(fn ($type) => (string) (int) $type)
        ->unique()
        ->values()
        ->all();

    $allTypeIds = array_map('strval', array_keys($typeLabels));

    // Server-rendered button text, so the picker reads correctly before Alpine
    // boots (and if it never does).
    $typeSummary = match (count($selectedTypes)) {
        0 => '-',
        1 => $typeLabels[(int) $selectedTypes[0]],
        default => count($selectedTypes) . ' types',
    };
@endphp

<form method="get" action="{{ route('items.index') }}" class="mb-6">
    <div class="space-y-4">
        <div>
            <input type="text" id="name" name="name" value="{{ request('name') }}" class="w-full input"
                placeholder="Search item by name" />
        </div>

        <div class="flex flex-wrap gap-4">
            <div class="flex flex-col w-full sm:w-auto">
                <label class="select w-full sm:w-auto">
                    <span class="label">Class</span>
                    <select id="class" name="class" class="select">
                        @foreach (collect(config('everquest.classes_short'))->sort() as $k => $v)
                            <option value="{{ $k }}" {{ request('class') == $k ? 'selected' : '' }}>
                                {{ $v }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            {{-- Item type picker: a multi-select dressed as the selects beside
                 it. The checkboxes are the form state, so what is ticked is
                 submitted whether or not the panel is open; Alpine only drives
                 the disclosure and the button's summary text. --}}
            <div class="flex flex-col w-full sm:w-auto">
                <div class="relative w-full sm:w-auto"
                    x-data="{
                        open: false,
                        selected: @js($selectedTypes),
                        labels: @js($typeLabels),
                        all: @js($allTypeIds),
                        get summary() {
                            if (this.selected.length === 0) return '-';
                            if (this.selected.length === 1) return this.labels[this.selected[0]] ?? '1 type';
                            return this.selected.length + ' types';
                        },
                    }"
                    @click.away="open = false"
                    @keydown.escape="open = false">
                    <button type="button" class="select w-full sm:w-auto text-left"
                        @click="open = !open" :aria-expanded="open">
                        <span class="label">Item Type</span>
                        <span class="truncate" x-text="summary">{{ $typeSummary }}</span>
                    </button>

                    <div x-show="open" x-cloak x-transition
                        class="absolute left-0 top-full mt-1 z-50 w-full sm:w-96
                               max-h-80 overflow-y-auto scrollbar-thin scrollbar-thumb-accent scrollbar-track-base-300
                               bg-base-200 border border-base-content/50 rounded shadow-lg p-3">
                        <div class="flex items-center gap-3 mb-3">
                            <button type="button" class="btn btn-xs btn-soft" @click="selected = [...all]">All</button>
                            <button type="button" class="btn btn-xs btn-soft" @click="selected = []">None</button>
                            <span class="text-xs text-gray-500">matches any ticked type</span>
                        </div>

                        @foreach ($typeGroups as $group => $types)
                            <div class="mb-3 last:mb-0">
                                <div class="text-xs uppercase tracking-wide text-gray-500 mb-1">{{ $group }}</div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1">
                                    @foreach ($types as $id => $name)
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                name="type[]"
                                                class="checkbox checkbox-sm shrink-0"
                                                value="{{ $id }}"
                                                x-model="selected"
                                                {{ in_array((string) $id, $selectedTypes, true) ? 'checked' : '' }}
                                            />
                                            {{-- wraps rather than truncates: two of these names
                                                 are longer than half the panel --}}
                                            <span class="text-sm">{{ $name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="flex flex-col w-full sm:w-auto">
                <label class="select w-full sm:w-auto">
                    <span class="label">Slot</span>
                    <select id="slot" name="slot" class="select">
                        <option value="">-</option>
                        @foreach (collect(config('everquest.slots'))->except($removeSlots)->sort() as $id => $islot)
                            <option value="{{ $id }}" {{ request('slot') == $id ? 'selected' : '' }}>
                                {{ $islot }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="flex flex-col w-full sm:w-auto">
                <label class="select w-full sm:w-auto">
                    <span class="label">Aug Slot</span>
                    <select id="augslot" name="augslot" class="select">
                        <option value="">-</option>
                        @foreach (collect(config('everquest.aug_slots')) as $id)
                            <option value="{{ $id }}" {{ request('augslot') == $id ? 'selected' : '' }}>
                                {{ $id }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="flex flex-row gap-4 w-full sm:w-auto">
                <div class="flex flex-col w-full sm:w-auto">
                    <label class="select w-full">
                        <span class="label">Min Lvl</span>
                        <select id="min_lvl" name="min_lvl" class="select w-full sm:w-auto">
                            <option value="">-</option>
                            @for ($i = 1; $i <= config('everquest.server_max_level'); $i++)
                                <option value="{{ $i }}" {{ request('min_lvl') == $i ? 'selected' : '' }}>
                                    {{ $i }}</option>
                            @endfor
                        </select>
                    </label>
                </div>
                <div class="flex flex-col w-full sm:w-auto">
                    <label class="select">
                        <span class="label">Max Lvl</span>
                        <select id="max_lvl" name="max_lvl" class="select w-full sm:w-auto">
                            <option value="">-</option>
                            @for ($i = 1; $i <= config('everquest.server_max_level'); $i++)
                                <option value="{{ $i }}" {{ request('max_lvl') == $i ? 'selected' : '' }}>
                                    {{ $i }}</option>
                            @endfor
                        </select>
                    </label>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
            <x-item-search-stat-filter
                id="stat1"
                :stats="$stats"
                :selected_stat="request('stat1')"
                :selected_stat_comp="request('stat1comp')"
                :stat_value="request('stat1val')"
            />
            <x-item-search-stat-filter
                id="stat2"
                :stats="$stats"
                :selected_stat="request('stat2')"
                :selected_stat_comp="request('stat2comp')"
                :stat_value="request('stat2val')"
            />
            <x-item-search-stat-filter
                id="stat3"
                :stats="$stats"
                :selected_stat="request('stat3')"
                :selected_stat_comp="request('stat3comp')"
                :stat_value="request('stat3val')"
            />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
            <div class="flex flex-col w-full sm:w-auto">
                <label class="input w-full">
                    <span class="label">Has Effect</span>
                    <input type="text" class="input" id="effect" name="effect"
                        value="{{ request('effect') }}" minlength="3" />
                </label>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
            <div class="flex flex-col w-full sm:w-auto">
                <div class="flex flex-col w-full sm:w-auto">
                    <label class="input w-48">
                        <span class="label">Bag Size >=</span>
                        <input type="number" class="input validator" min="0" max="200"
                            title="Must be between 0 and 200" id="bagslots" name="bagslots"
                            value="{{ request('bagslots') }}" maxlength="3" />
                    </label>
                </div>
            </div>

            {{-- Food/drink/alcohol strength. Leads with the numeric band because
                 the wording differs per type and alcohol has none at all. --}}
            <div class="flex flex-col w-full sm:w-auto">
                <label class="select w-full">
                    <span class="label">Consumable Strength</span>
                    <select id="strength" name="strength" class="select">
                        <option value="">-</option>
                        @foreach (config('everquest.consumable_strengths') as $key => $band)
                            <option value="{{ $key }}" {{ request('strength') == $key ? 'selected' : '' }}>
                                {{ $band['min'] }}{{ $band['max'] === null ? '+' : '-' . $band['max'] }}
                                &mdash; {{ $band['search'] }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        {{-- Presence toggles. "Has Effect" above narrows by effect name; these
             just ask whether the item has one of that kind at all. --}}
        <div class="flex flex-wrap gap-x-6 gap-y-2 mt-4">
            <label class="flex items-center gap-2 cursor-pointer">
                <input
                    type="checkbox"
                    name="focus"
                    class="checkbox"
                    value="1"
                    {{ request('focus') ? 'checked' : '' }}
                />
                <span>Has Focus Effect</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input
                    type="checkbox"
                    name="click"
                    class="checkbox"
                    value="1"
                    {{ request('click') ? 'checked' : '' }}
                />
                <span>Has Clickable Effect</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input
                    type="checkbox"
                    name="anystat"
                    class="checkbox"
                    value="1"
                    {{ request('anystat') ? 'checked' : '' }}
                />
                <span title="A stat, resist, focus or click -- AC on its own does not count">Has Any Stat</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input
                    type="checkbox"
                    name="evo"
                    class="checkbox"
                    value="1"
                    {{ request('evo') ? 'checked' : '' }}
                />
                <span>Evolution Items</span>
            </label>
        </div>

        {{-- Era checklist. Only rendered once `php artisan items:index-eras` has
             built the index -- without it every box would match nothing. --}}
        @if (!empty($eraOptions))
            {{-- Always starts collapsed: twenty boxes is a lot of form to look
                 at when you are not filtering by era, and opening it for a
                 ticked search would re-expand on every paginate and sort. The
                 count badge is what advertises an active filter instead.
                 x-show (not x-if) keeps the inputs in the DOM, so a closed
                 checklist still submits what is ticked. --}}
            <div class="mt-6" x-data="{
                open: false,
                set(checked) {
                    this.$refs.eraList.querySelectorAll('input[type=checkbox]').forEach(box => box.checked = checked);
                }
            }">
                <button type="button" @click="open = !open" :aria-expanded="open"
                    class="flex items-center gap-2 btn btn-sm btn-soft transition">
                    <span>Era</span>
                    @if ($selectedEras)
                        <span class="badge badge-sm badge-info">{{ count($selectedEras) }}</span>
                    @endif
                    <svg xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4 transition-transform duration-200"
                        :class="{ 'rotate-180': open }"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <div x-show="open" x-cloak x-transition class="mt-3">
                    <div class="flex items-center gap-3 mb-2">
                        <button type="button" class="btn btn-xs btn-soft" @click="set(true)">All</button>
                        <button type="button" class="btn btn-xs btn-soft" @click="set(false)">None</button>
                        <span class="text-xs text-gray-500">earliest era the item can be obtained in</span>
                    </div>
                    <div x-ref="eraList" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-x-4 gap-y-2">
                        @foreach ($eraOptions as $era)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    name="expansion[]"
                                    class="checkbox checkbox-sm"
                                    value="{{ $era }}"
                                    {{ in_array($era, $selectedEras, true) ? 'checked' : '' }}
                                />
                                <span class="text-sm truncate">{{ ContentFilter::label($era) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>

    <div class="pt-4">
        <button type="submit" class="btn btn-soft">
            Search
        </button>
        <a href="{{ route('items.index') }}" class="btn btn-soft btn-error">
            Reset
        </a>
    </div>
</form>
