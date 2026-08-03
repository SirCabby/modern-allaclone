<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemExpansion;
use App\Models\Zone;
use App\Filters\ItemFilter;
use Illuminate\Http\Request;
use App\ViewModels\ItemViewModel;
use Illuminate\Support\Facades\Cache;
use App\Models\QuestScript;
use App\Models\Task;
use App\Support\ContentFilter;
use App\Support\ItemCategories;

class ItemController extends Controller
{
    public function index(Request $request)
    {
        $discoveryEnabled = config('everquest.discovered_items.enable');

        $request->validate([
            'stat1comp' => 'in:1,2,5',
            'stat2comp' => 'in:1,2,5',
            'stat3comp' => 'in:1,2,5',
            'expansion' => 'array',
            'expansion.*' => 'integer|min:0|max:99',
            // No `type => array` rule: links from when this was a single select
            // still pass a scalar, and the filter takes either.
            'type.*' => 'integer',
        ]);

        $items = collect();
        if ($request->query->count() > 0) {
            $query = (new ItemFilter($request))->apply(Item::query());

            if ($discoveryEnabled) {
                $query->whereHas('discovery');
            }

            $query->select([
                'id', 'Name', 'icon', 'ac', 'hp', 'damage', 'delay',
                'augtype', 'bagslots', 'bagwr',
                // consumables carry their strength in casttime_
                'casttime_',
                // itemtype, slots and the effect columns, which is what the Type
                // column needs to name every category an item answers to
                ...ItemCategories::REQUIRED_COLUMNS,
                // where that click fires from, for the Click column
                'clicktype',
                'mana', 'endur', 'haste', 'aagi', 'acha', 'adex', 'aint', 'asta', 'astr', 'awis',
                'heroic_agi', 'heroic_cha', 'heroic_dex', 'heroic_int', 'heroic_sta', 'heroic_str', 'heroic_wis',
                'attack', 'regen', 'manaregen', 'enduranceregen', 'spellshield', 'combateffects', 'shielding',
                'damageshield', 'dotshielding', 'dsmitigation', 'avoidance', 'accuracy', 'stunresist',
                'strikethrough', 'spelldmg',
            ]);

            $items = $query->sortable()->paginate(50)->withQueryString();
        }

        // Eras live in sqlite and items in peq, so the era column is a second
        // lookup for the page's fifty rows rather than a join. The zone column
        // is a third, for the handful of zone names those rows name.
        $itemEras = ItemExpansion::forItems($items->pluck('id')->all());

        return view('items.index', [
            'items' => $items,
            'itemEras' => $itemEras,
            'eraZones' => Zone::byShortNames(
                $itemEras->pluck('zone')->filter()->unique()->values()->all()
            ),
            'eraOptions' => ItemExpansion::availableEras(),
            'metaTitle' => config('app.name') . ' - Item Search',
        ]);
    }

    public function show(Item $item)
    {
        // Forage/fishing/merchant lookups are era-gated, so the era belongs in the key.
        $era = ContentFilter::currentExpansion();

        $itemCache = Cache::remember("items.show.{$item->id}.e{$era}", now()->addDay(), function () use ($item) {
            $item = Item::with(['evolvingDetails.item', 'discovery'])
                ->where('id', $item->id)
                ->firstOrFail();
            $vm = (new ItemViewModel($item))->withEffects();

            return [
                'item' => $item,
                'recipes' => $vm->recipes(),
                'used_in_ts' => $vm->usedInTradeskills(),
                'forage' => $vm->forageZones(),
                'fishing' => $vm->fishingZones(),
                'soldByZone' => $vm->soldInZones(),
                'ground_spawn' => $vm->itemGroundSpawn(),
            ];
        });

        // Quest scripts come from the on-disk index, not peq, so they are not
        // era-gated and sit outside the cached payload above.
        $questScripts = QuestScript::forItem($item->id)->get();

        // Tasks that reward or ask for this item -- the reverse of the reward and
        // objective lists on the task page. Not era-gated either, but the lookup
        // scans tasks.reward_id_list, so it is worth caching.
        $tasks = Cache::remember(
            "items.show.{$item->id}.tasks",
            now()->addDay(),
            fn () => Task::forItem($item->id)
        );

        return view('items.show', [
            ...$itemCache,
            'questScripts' => $questScripts,
            'tasks' => $tasks,
            ...$this->eraFor($item),
            'metaTitle' => config('app.name') . ' - Item: ' . $item->Name,
        ]);
    }

    public function popup(Item $item)
    {
        $item = Item::where('id', $item->id)->firstOrFail();
        (new ItemViewModel($item))->withEffects();

        return response()->json([
            'html' => view('items.partials.popup', [
                'item' => $item,
                ...$this->eraFor($item),
            ])->render()
        ]);
    }

    /**
     * The item's era and the zone that dated it, for the detail panel.
     *
     * Lives in sqlite rather than peq and describes what the item *is*, so --
     * like the quest scripts -- it is not era-gated and stays out of the
     * era-keyed cached payload. The zone is a second lookup because the index
     * stores a short name and cannot join across connections; it is null for
     * crafted and LDoN-flagged items, which are dated without a place.
     */
    private function eraFor(Item $item): array
    {
        $itemEra = ItemExpansion::forItem($item->id);

        return [
            'itemEra' => $itemEra,
            'eraZone' => $itemEra?->zone
                ? Zone::forEra(Zone::byShortNames([$itemEra->zone]), $itemEra->zone, $itemEra->expansion)
                : null,
        ];
    }

    public function drops_by_zone(Item $item)
    {
        // Era belongs in the key: a forever-cached payload computed under one
        // era would otherwise keep serving that era's drops after a switch.
        $era = ContentFilter::currentExpansion();

        $drops = Cache::rememberForever("items.drops_by_zone.{$item->id}.e{$era}", function () use ($item) {
            return (new ItemViewModel($item))->dropsByZone();
        });

        return response()->json([
            'drops_by_zone' => $drops['drops_by_zone'],
            'top_npcs' => $drops['top_npcs'],
        ]);
    }
}
