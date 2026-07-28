<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Zone;
use App\Filters\ItemFilter;
use Illuminate\Http\Request;
use App\ViewModels\ItemViewModel;
use Illuminate\Support\Facades\Cache;
use App\Models\QuestScript;
use App\Models\Task;
use App\Support\ContentFilter;

class ItemController extends Controller
{
    public function index(Request $request)
    {
        $discoveryEnabled = config('everquest.discovered_items.enable');

        $request->validate([
            'stat1comp' => 'in:1,2,5',
            'stat2comp' => 'in:1,2,5',
            'stat3comp' => 'in:1,2,5',
        ]);

        $items = collect();
        if ($request->query->count() > 0) {
            $query = (new ItemFilter($request))->apply(Item::query());

            if ($discoveryEnabled) {
                $query->whereHas('discovery');
            }

            $query->select([
                'id', 'Name', 'icon', 'itemtype', 'ac', 'hp', 'damage', 'delay',
                'augtype', 'slots', 'bagslots', 'bagwr',
                'mana', 'endur', 'haste', 'aagi', 'acha', 'adex', 'aint', 'asta', 'astr', 'awis',
                'heroic_agi', 'heroic_cha', 'heroic_dex', 'heroic_int', 'heroic_sta', 'heroic_str', 'heroic_wis',
                'attack', 'regen', 'manaregen', 'enduranceregen', 'spellshield', 'combateffects', 'shielding',
                'damageshield', 'dotshielding', 'dsmitigation', 'avoidance', 'accuracy', 'stunresist',
                'strikethrough', 'spelldmg',
            ]);

            $items = $query->sortable()->paginate(50)->withQueryString();
        }

        return view('items.index', [
            'items' => $items,
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
            'metaTitle' => config('app.name') . ' - Item: ' . $item->Name,
        ]);
    }

    public function popup(Item $item)
    {
        $item = Item::where('id', $item->id)->firstOrFail();
        (new ItemViewModel($item))->withEffects();

        return response()->json([
            'html' => view('items.partials.popup', ['item' => $item])->render()
        ]);
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
