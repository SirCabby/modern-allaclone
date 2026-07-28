<?php
namespace App\ViewModels;

use App\Models\Item;
use App\Models\Zone;
use App\Models\Forage;
use App\Models\Fishing;
use App\Models\NpcType;
use App\Models\GroundSpawn;
use App\Models\TradeskillRecipe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Support\ContentFilter;

class ItemViewModel
{
    public function __construct(public Item $item)
    {
        $relations = [];

        if ($item->proceffect > 0 && $item->proceffect < 65535) {
            $relations[] = 'procEffectSpell';
        }

        if ($item->worneffect > 0 && $item->worneffect < 65535) {
            $relations[] = 'wornEffectSpell';
        }

        if ($item->focuseffect > 0 && $item->focuseffect < 65535) {
            $relations[] = 'focusEffectSpell';
        }

        if ($item->clickeffect > 0 && $item->clickeffect < 65535) {
            $relations[] = 'clickEffectSpell';
        }

        if ($item->scrolleffect > 0 && $item->scrolleffect < 65535) {
            $relations[] = 'scrollEffectSpell';
        }

        if (!empty($relations)) {
            $this->item->load($relations);
        }
    }

    public function withEffects()
    {
        $i = $this->item;

        $i->custom_proceffect = $i->relationLoaded('procEffectSpell') && $i->procEffectSpell
            ? $i->procEffectSpell->name
            : null;

        $i->custom_worneffect = $i->relationLoaded('wornEffectSpell') && $i->wornEffectSpell
            ? $i->wornEffectSpell->name
            : null;

        $i->custom_focuseffect = $i->relationLoaded('focusEffectSpell') && $i->focusEffectSpell
            ? $i->focusEffectSpell->name
            : null;

        $i->custom_clickeffect = $i->relationLoaded('clickEffectSpell') && $i->clickEffectSpell
            ? $i->clickEffectSpell->name
            : null;

        $i->custom_scrolleffect = $i->relationLoaded('scrollEffectSpell') && $i->scrollEffectSpell
            ? $i->scrollEffectSpell->name
            : null;

        return $this;
    }

    public function dropsByZone(): array
    {
        $ignoreZones = config('everquest.ignore_zones') ?? [];
        $excludeMerchants = config('everquest.merchants_dont_drop_stuff') ?? true;

        $allZones = Cache::rememberForever('all_zones_drops', function () {
            return Zone::select('id', 'short_name', 'long_name', 'version', 'expansion')
                ->orderBy('id')
                ->get();
        });

        $itemId = $this->item->id;

        $results = DB::connection('eqemu')->table('items')
            ->join('lootdrop_entries', 'items.id', '=', 'lootdrop_entries.item_id')
            ->join('lootdrop', 'lootdrop_entries.lootdrop_id', '=', 'lootdrop.id')
            ->join('loottable_entries', 'lootdrop.id', '=', 'loottable_entries.lootdrop_id')
            ->join('loottable', 'loottable_entries.loottable_id', '=', 'loottable.id')
            ->join('npc_types', 'loottable_entries.loottable_id', '=', 'npc_types.loottable_id')
            ->join('spawnentry', 'npc_types.id', '=', 'spawnentry.npcID')
            ->join('spawn2', 'spawnentry.spawngroupID', '=', 'spawn2.spawngroupID')
            ->where('items.id', $itemId)
            ->where('spawnentry.chance', '>', 0)
            ->tap(fn ($q) => ContentFilter::apply($q, 'lootdrop_entries'))
            ->tap(fn ($q) => ContentFilter::apply($q, 'lootdrop'))
            ->tap(fn ($q) => ContentFilter::apply($q, 'loottable'))
            ->tap(fn ($q) => ContentFilter::apply($q, 'spawnentry'))
            ->tap(fn ($q) => ContentFilter::apply($q, 'spawn2'))
            ->when($excludeMerchants, fn($q) => $q->where('npc_types.merchant_id', 0))
            ->when(!empty($ignoreZones), fn($q) => $q->whereNotIn('spawn2.zone', $ignoreZones))
            ->select([
                'spawn2.zone',
                'spawn2.version',
                'npc_types.id as npc_id',
                'npc_types.name as npc_name',
                'lootdrop_entries.chance as lootdrop_chance',
                'loottable_entries.probability',
                'loottable_entries.multiplier',
                'loottable_entries.loottable_id',
            ])
            ->distinct()
            ->get();

        $grouped = $results->groupBy(function ($row) {
            return strtolower($row->zone) . '-' . $row->version;
        });


        $drops = [];
        foreach ($grouped as $zoneKey => $npcs) {
            [$zoneShortName, $version] = explode('-', $zoneKey);

            $zoneData = $allZones->where('short_name', $zoneShortName)
                                 ->where('version', (int) $version)
                                 ->first();

            if (!$zoneData || !ContentFilter::zoneInEra($zoneData->expansion)) {
                continue;
            }

            $drops[] = [
                'zone' => $zoneShortName,
                'zone_name' => $zoneData->long_name ?? $zoneShortName,
                'version' => (int) $version,
                'npcs' => $npcs
                    ->unique('npc_name')
                    ->filter(function ($npc) {
                        $npcCleanName = NpcType::npcFixName($npc->npc_name);
                        return !empty(trim($npcCleanName ?? ''));
                    })
                    ->map(function ($npc) {
                        $npcCleanName = NpcType::npcFixName($npc->npc_name);
                        return [
                            'id'            => $npc->npc_id,
                            'name'          => $npc->npc_name,
                            'clean_name'    => $npcCleanName,
                            'chance'        => $npc->lootdrop_chance,
                            'probability'   => $npc->probability,
                            'multiplier'    => $npc->multiplier,
                            'loottable_id'  => $npc->loottable_id,
                        ];
                })->sortBy('clean_name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            ];
        }

        $drops_by_zone = collect($drops)->sortBy(fn($group) => $group['zone_name'])->values();

        $allNpcs = collect($drops_by_zone)->flatMap(function ($zone) {
            return collect($zone['npcs'])->map(function ($npc) use ($zone) {
                return array_merge($npc, [
                    'zone'      => $zone['zone'],
                    'zone_name' => $zone['zone_name'],
                    'version'   => $zone['version'],
                ]);
            });
        });

        $sanitizedNpcs = $allNpcs->filter(function ($npc) {
            return isset($npc['id'], $npc['clean_name'], $npc['zone_name'], $npc['chance']);
        });

        $top_npcs = $sanitizedNpcs
            ->sortByDesc('chance')
            ->unique('id')
            ->take(10)
            ->values()
            ->all();

        return [
            'drops_by_zone' => $drops_by_zone,
            'top_npcs'      => $top_npcs ?? [],
        ];
    }

    public function recipes(): Collection
    {
        return TradeskillRecipe::whereHas('entries', function ($q) {
            $q->where('item_id', $this->item->id)
                ->where('successcount', '>', 0);
        })
        ->tap(fn ($q) => ContentFilter::apply($q))
        ->select('id', 'name', 'tradeskill', 'trivial', 'nofail')
        ->groupBy('id', 'name', 'tradeskill')
        ->get();
    }

    public function usedInTradeskills(): Collection
    {
        return TradeskillRecipe::whereHas('entries', function ($q) {
            $q->where('item_id', $this->item->id)
                ->where(function ($sub) {
                    $sub->where('componentcount', '>', 0)->orWhere('iscontainer', '=', 1);
                });
        })
        ->tap(fn ($q) => ContentFilter::apply($q))
        ->select('id', 'name', 'tradeskill')
        ->groupBy('id', 'name', 'tradeskill')
        ->get();
    }

    public function forageZones(): Collection
    {
        $expansions = config('everquest.expansions');

        return Forage::with('zone')
            ->where('itemid', $this->item->id)
            ->tap(fn ($q) => ContentFilter::apply($q))
            ->select('zoneid', 'chance', 'level')
            ->groupBy('zoneid', 'chance', 'level')
            ->get()
            // zoneid 0 is global forage; only rows tied to an out-of-era zone hide.
            ->filter(fn ($forage) => !$forage->zone || ContentFilter::zoneInEra($forage->zone->expansion))
            ->map(function ($forage) use($expansions) {
                $zone = $forage->zone;
                $expansionName = $zone && isset($expansions[$zone->expansion])
                    ? " ({$expansions[$zone->expansion]})"
                    : '';
                return [
                    'zone_id' => $zone->id ?? null,
                    'short_name' => $zone->short_name ?? null,
                    'long_name' => $zone->long_name ?? 'Unknown',
                    'expansion' => $expansionName,
                    'chance' => $forage->chance,
                    'level' => $forage->level,
                ];
            })->sortBy('long_name')
            ->values();
    }

    public function fishingZones(): Collection
    {
        $expansions = config('everquest.expansions');

        return Fishing::with('zone')
            ->where('itemid', $this->item->id)
            ->where('zoneid', '>', 0)
            ->tap(fn ($q) => ContentFilter::apply($q))
            ->select('zoneid', 'chance', 'skill_level')
            ->groupBy('zoneid', 'chance', 'skill_level')
            ->get()
            ->filter(fn ($fishing) => !$fishing->zone || ContentFilter::zoneInEra($fishing->zone->expansion))
            ->map(function ($fishing) use($expansions) {
                $zone = $fishing->zone;
                $expansionName = $zone && isset($expansions[$zone->expansion])
                    ? " ({$expansions[$zone->expansion]})"
                    : '';
                return [
                    'zone_id' => $zone->id ?? null,
                    'short_name' => $zone->short_name ?? null,
                    'long_name' => $zone->long_name ?? 'Unknown',
                    'expansion' => $expansionName,
                    'chance' => $fishing->chance,
                    'level' => $fishing->skill_level,
                ];
            })->sortBy('long_name')
            ->values();
    }

    public function soldInZones(): Collection
    {
        return NpcType::whereHas('merchantlist', function ($q) {
                ContentFilter::apply($q);
                $q->where('item', $this->item->id);
            })
            ->whereHas('spawnentries', function ($q) {
                ContentFilter::apply($q);
                $q->whereHas('spawn2', function ($spawn2) {
                    ContentFilter::apply($spawn2);
                    $spawn2->whereHas('zoneData', fn ($zone) => ContentFilter::applyZone($zone));
                });
            })
            ->with([
                'spawnentries' => fn ($q) => ContentFilter::apply($q),
                'spawnentries.spawn2' => fn ($q) => ContentFilter::apply($q),
                'spawnentries.spawn2.zoneData',
            ])
            ->get()
            ->map(function ($npc) {
                $spawn = $npc->spawnentries->pluck('spawn2')
                    ->filter(fn ($s) => $s && ContentFilter::zoneInEra($s->zoneData?->expansion))
                    ->first();
                $zone = $spawn?->zoneData;

                return [
                    'id' => $npc->id,
                    'name' => $npc->name,
                    'clean_name' => $npc->clean_name,
                    'zone' => $spawn?->zone,
                    'zone_long_name' => $zone?->long_name,
                    'class' => $npc->class,
                ];
            })->groupBy('zone')->map(function ($items, $zone) {
                return [
                    'zone' => $zone,
                    'zone_name' => $items->first()['zone_long_name'] ?? '',
                    'npcs' => $items->map(fn($npc) => [
                        'id' => $npc['id'],
                        'name' => $npc['name'],
                        'clean_name' => $npc['clean_name'],
                        'class' => $npc['class'],
                    ])->values(),
                ];
            })->sortBy('zone_name')
            ->values();
    }

    public function itemGroundSpawn(): Collection
    {
        return GroundSpawn::select(['zoneid', 'max_x', 'max_y', 'max_z'])
            ->with(['zone:id,zoneidnumber,long_name,expansion'])
            ->where('item', $this->item->id)
            ->tap(fn ($q) => ContentFilter::apply($q))
            ->get()
            ->filter(fn ($spawn) => !$spawn->zone || ContentFilter::zoneInEra($spawn->zone->expansion))
            ->map(function ($spawn) {
                return [
                    'zone_id' => $spawn->zone?->zoneidnumber,
                    'zone_name' => $spawn->zone?->long_name,
                    'x' => $spawn->max_x,
                    'y' => $spawn->max_y,
                    'z' => $spawn->max_z,
                ];
            })
            ->groupBy('zone_id')
            ->map(function ($items, $zoneId) {
                return [
                    'zone' => $zoneId,
                    'zone_name' => $items->first()['zone_name'] ?? '',
                    'spawns' => $items->map(fn($item) => [
                        'x' => $item['x'],
                        'y' => $item['y'],
                        'z' => $item['z'],
                    ])->values(),
                ];
            })->sortBy('zone_name')
            ->values();
    }
}
