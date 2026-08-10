<?php

namespace App\Http\Controllers;

use App\Filters\DiscoveredItemFilter;
use App\Models\DiscoveredItem;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DiscoveredItemController extends Controller
{
    public function index(Request $request)
    {
        if (!config('everquest.discovered_items.enable')) {
            abort(404);
        }

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = DiscoveredItem::query()
            ->join('character_data', 'character_data.name', '=', 'discovered_items.char_name')
            ->where('character_data.gm', 0)
            ->with('item')
            ->select('discovered_items.*')
            ->sortable(['discovered_date' => 'desc']);

        $query = (new DiscoveredItemFilter($request))->apply($query);
        $items = $query->paginate(50)->withQueryString();

        return view('discovery.index', [
            'items' => $items,
            'metaTitle' => config('app.name') . ' - Discovered Items',
        ]);
    }

    public function leaderboard()
    {
        if (!config('everquest.discovered_items.enable')) {
            abort(404);
        }

        $leaders = Cache::remember('discovery.leaderboard.top25', now()->addMinutes(15), function () {
            $leaders = DiscoveredItem::query()
                ->join('character_data', 'character_data.name', '=', 'discovered_items.char_name')
                ->where('character_data.gm', 0)
                ->selectRaw('
                    discovered_items.char_name,
                    COUNT(*) as total_discovered,
                    MAX(discovered_items.discovered_date) as last_discovered_at
                ')
                ->groupBy('discovered_items.char_name')
                ->orderByDesc('total_discovered')
                ->limit(25)
                ->get();

            $latestRows = DiscoveredItem::query()
                ->select('char_name', 'item_id', 'discovered_date')
                ->whereIn('char_name', $leaders->pluck('char_name'))
                ->orderByDesc('discovered_date')
                ->get()
                ->groupBy('char_name')
                ->map(fn($rows) => $rows->first());

            $itemIds = $latestRows->pluck('item_id')->filter()->unique()->values();
            $items = Item::whereIn('id', $itemIds)->get()->keyBy('id');

            foreach ($leaders as $leader) {
                $latest = $latestRows[$leader->char_name] ?? null;
                $leader->latest_item = $latest ? ($items[$latest->item_id] ?? null) : null;
                $leader->latest_discovery_at = $latest?->discovered_at;
            }

            return $leaders;
        });

        return view('discovery.leaderboard', [
            'leaders' => $leaders,
            'metaTitle' => config('app.name') . ' - Discovered Items Leaderboard',
        ]);
    }
}
