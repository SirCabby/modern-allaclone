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
            fn () => Zone::getLiveZones($currentExpansion)
        );

        return view('zones.index', [
            'zones' => $zones,
            'metaTitle' => config('app.name') . ' - Zones',
        ]);
    }

    public function show(Zone $zone, Request $request)
    {
        abort_if(in_array($zone->short_name, config('everquest.ignore_zones', [])), 404);

        $version = (int) $request->query('v', 0);

        $era = ContentFilter::currentExpansion();

        // Era is part of the key: spawns, drops and merchants are all gated by it.
        $zoneCache = Cache::rememberForever("zones.show.{$zone->id}_v{$version}_e{$era}", function () use ($zone, $version) {
            $zone = Zone::where('id', $zone->id)
                ->with('zonepoints', function ($q) use ($version) {
                    ContentFilter::apply($q);
                    $q->when($version > 0, fn ($q) => $q->where('version', $version))
                        ->groupBy('target_zone_id')
                        // Constraining the target hides connections into zones
                        // the presented era has not opened yet.
                        ->with(['targetZones' => fn ($z) => ContentFilter::applyZone($z)
                            ->select('id', 'zoneidnumber', 'short_name', 'long_name', 'expansion')]);
                })
                ->when($version > 0, fn ($q) => $q->where('version', $version))
                ->firstOrFail();

            $vm = new ZoneViewModel($zone, $version);

            return [
                'zone' => $zone,
                'npcs' => $vm->npcs(),
                'drops' => $vm->drops(),
                'spawnGroups' => $vm->spawnGroups(),
                'foraged' => $vm->foraged(),
                'fished' => $vm->fished(),
                'connectedZones' => $vm->connectedZones(),
                'tasks' => $vm->tasks(),
            ];
        });

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

        // zone version for meta title
        $zone = $zoneCache['zone'];
        $zversion = $zone->version ? ' - version (' . $zone->version . ')' : '';

        return view('zones.show', [
            ...$zoneCache,
            'altCurrency' => $altCurrency,
            'discoveredItems' => $discoveredItems,
            'metaTitle' => config('app.name') . ' - Zone: ' . $zone->long_name . $zversion,
        ]);
    }
}
