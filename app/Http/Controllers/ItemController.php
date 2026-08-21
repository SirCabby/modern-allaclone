<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemExpansion;
use App\Models\ItemList;
use App\Models\Zone;
use App\Filters\ItemFilter;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
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

        // `nullable` on everything the form can submit blank. A GET form posts
        // every control it has, so clearing one sends `list=` rather than
        // dropping it -- and ConvertEmptyStringsToNull turns that into null,
        // which a bare `string`/`array`/`in` rule rejects. That failure is a
        // redirect back to the page you came from, which still carries the
        // filter you were trying to clear, so the control springs back.
        $request->validate([
            'stat1comp' => 'nullable|in:1,2,5',
            'stat2comp' => 'nullable|in:1,2,5',
            'stat3comp' => 'nullable|in:1,2,5',
            'expansion' => 'nullable|array',
            'expansion.*' => 'integer|min:0|max:99',
            // A slug no list answers to is dropped, not rejected -- see list()
            // in ItemFilter; the length cap is all this needs to say.
            'list' => 'nullable|string|max:64',
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
                // how far a bow, a throwing weapon or an arrow reaches
                'range',
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

            $sort = (string) $request->query('sort');

            $items = in_array($sort, ItemExpansion::ORDERABLE, true)
                ? $this->paginateByIndex($query, $sort, $request->query('direction'))
                : $query->sortable()->paginate(50)->withQueryString();
        }

        // Eras live in sqlite and items in peq, so the era column is a second
        // lookup for the page's fifty rows rather than a join. The zone column
        // is a third, for the handful of zone names those rows name.
        $itemEras = ItemExpansion::forItems($items->pluck('id')->all());

        // The lists are what the picker offers; the active one is looked up
        // among them rather than separately, so a slug the picker cannot show
        // is one the page reports as not applied -- which is what the filter
        // does with it too.
        $itemLists = ItemList::available();
        $activeList = $itemLists->firstWhere('slug', $request->query('list'));

        return view('items.index', [
            'items' => $items,
            'itemEras' => $itemEras,
            'eraZones' => Zone::byShortNames(
                $itemEras->pluck('zone')->filter()->unique()->values()->all()
            ),
            'eraOptions' => ItemExpansion::availableEras(),
            'itemLists' => $itemLists,
            'activeList' => $activeList,
            'metaTitle' => config('app.name') . ' - Item Search',
        ]);
    }

    /**
     * Page a search ordered by Era or Zone.
     *
     * Neither column can go in the ORDER BY: the era index is sqlite and the
     * items are peq, so the ordering has to be applied between them. The
     * search hands over the ids it matched -- one narrow query, ~118k ints at
     * the very worst -- and they are walked in index order to pick out the
     * page, which is then read back in full. Two cheap queries rather than one
     * with forty thousand integers glued into it.
     */
    private function paginateByIndex($query, string $by, $direction)
    {
        $perPage = 50;
        $matched = array_flip($this->matchingIds($query));
        $ordered = [];

        foreach (ItemExpansion::orderedItemIds($by) as $id) {
            if (isset($matched[$id])) {
                $ordered[] = $id;
                unset($matched[$id]);
            }
        }

        if ($direction === 'desc') {
            $ordered = array_reverse($ordered);
        }

        // What the index never dated shows "-" in both columns, so it sits at
        // the bottom whichever way the column points -- the same call potency
        // makes about the items that have none.
        $undated = array_keys($matched);
        sort($undated);

        $ordered = array_merge($ordered, $undated);

        $page = Paginator::resolveCurrentPage();
        $pageIds = array_slice($ordered, ($page - 1) * $perPage, $perPage);

        $rows = $pageIds
            ? (clone $query)->whereIntegerInRaw('items.id', $pageIds)->get()->keyBy('id')
            : collect();

        return new LengthAwarePaginator(
            collect($pageIds)->map(fn ($id) => $rows->get($id))->filter()->values(),
            count($ordered),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => request()->query()]
        );
    }

    /**
     * Every id the search matched, as a flat array.
     *
     * Down at PDO rather than through pluck() because an unfiltered search is
     * ninety thousand rows, and hydrating that many single-column stdClass
     * objects on the way to discarding them costs four times what the query
     * itself does.
     */
    private function matchingIds($query): array
    {
        $base = (clone $query)->toBase()->select('items.id');
        $connection = $base->getConnection();

        $statement = $connection->getReadPdo()->prepare($base->toSql());
        $statement->execute($connection->prepareBindings($base->getBindings()));

        return $statement->fetchAll(\PDO::FETCH_COLUMN);
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
