<?php

namespace App\Http\Controllers;

use App\Models\AlternateCurrency;
use App\Models\DiscoveredItem;
use App\Models\Zone;
use App\ViewModels\ZoneViewModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Support\ContentFilter;

class ZoneController extends Controller
{
    public function index(Request $request)
    {
        $currentExpansion = ContentFilter::currentExpansion();

        // Cache key must carry the era, or switching eras would serve the
        // previous era's zone list.
        $zones = Cache::remember(
            "zones.index.e{$currentExpansion}",
            now()->addDay(),
            fn () => Zone::getLiveZones()
        );

        return view('zones.index', [
            'zones' => $zones,
            'metaTitle' => config('app.name') . ' - Zones',
        ]);
    }

    public function show(Zone $zone)
    {
        abort_if(in_array($zone->short_name, config('everquest.ignore_zones', [])), 404);

        // The route already resolved a version: every version of a zone is its
        // own row with its own id. The old ?v= only ever repeated what the row
        // says, so it is no longer read -- links that omitted it were served
        // v0's spawns under another version's page.
        $version = (int) $zone->version;

        $era = ContentFilter::currentExpansion();

        // Era is part of the key: spawns, drops and merchants are all gated by it.
        $zoneCache = Cache::rememberForever(
            "zones.show.{$zone->id}_v{$version}_e{$era}",
            fn () => ZoneViewModel::payload($zone->id)
        );

        // get cached alt currency since tasks could use it
        $altCurrency = AlternateCurrency::allAltCurrency();

        $discoveredItems = collect();
        if (config('everquest.discovered_items.enable')) {
            $itemIds = collect()
                ->merge(collect($zoneCache['drops'])->pluck('item.id'))
                ->merge(collect($zoneCache['foraged'])->pluck('item.id'))
                ->merge(collect($zoneCache['fished'])->pluck('item.id'))
                ->unique()
                ->values();

            $discoveredItems = DiscoveredItem::whereIn('item_id', $itemIds)
                ->pluck('item_id')
                ->flip();
        }

        // display_name carries the version where the era runs more than one of
        // this zone, so tabs and search engines get a title per version rather
        // than three pages all called "Muramite Proving Grounds".
        $zone = $zoneCache['zone'];

        return view('zones.show', [
            ...$zoneCache,
            // Outside the payload deliberately: it is one indexed lookup, it
            // keeps the shape of entries cached forever unchanged, and the
            // links stay right when a version opens without the page itself
            // having changed.
            'otherVersions' => $zone->otherLiveVersions(),
            'altCurrency' => $altCurrency,
            'discoveredItems' => $discoveredItems,
            'metaTitle' => config('app.name') . ' - Zone: ' . $zone->display_name
                . ' (' . $zone->short_name . ')',
        ]);
    }
}
