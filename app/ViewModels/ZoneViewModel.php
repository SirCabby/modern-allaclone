<?php
namespace App\ViewModels;

use App\Models\Item;
use App\Models\Task;
use App\Models\Zone;
use App\Models\NpcType;
use App\Models\SpawnTwo;
use App\Support\ContentFilter;
use App\Support\ItemCategories;
use Illuminate\Support\Collection;

class ZoneViewModel
{
    protected Zone $zone;
    protected int $version;

    public function __construct(Zone $zone, int $version = 0)
    {
        $this->zone = $zone;
        $this->version = $version;
    }

    /**
     * Everything zones.show renders for one zone row, in the shape the page
     * cache stores.
     *
     * The controller and `app:cache-zones` both build the page through here so
     * a warmed entry is what the request would have built. They had drifted:
     * the warmer skipped the content filter on zone points, so warming wrote
     * connections into unopened zones under the key the request reads.
     *
     * The version comes off the row rather than the caller, because the row is
     * already a version -- `paw` v0 and v1 are separate ids -- and any link
     * that reached the page without repeating it in the query string used to
     * get v0's spawns.
     */
    public static function payload(int $zoneId): array
    {
        $zone = Zone::findOrFail($zoneId);
        $version = (int) $zone->version;

        $zone->load(['zonepoints' => function ($q) use ($version) {
            ContentFilter::apply($q);
            $q->when($version > 0, fn ($q) => $q->where('version', $version))
                ->groupBy('target_zone_id')
                // Constraining the target hides connections into zones the
                // presented era has not opened yet.
                ->with(['targetZones' => fn ($z) => ContentFilter::applyZone($z)
                    ->select('id', 'zoneidnumber', 'short_name', 'long_name', 'expansion')]);
        }]);

        $vm = new self($zone, $version);

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
    }

    public function connectedZones(): Collection
    {
        return $this->zone->zonepoints
            ->pluck('targetZones')
            ->filter()
            ->unique('id')
            ->sortBy('long_name')
            ->values();
    }

    public function npcs(): Collection
    {
        $zone_short = $this->zone->short_name;
        $zone_id = $this->zone->zoneidnumber;

        $query = NpcType::whereHas('spawnentries', function ($entry) use ($zone_short) {
            ContentFilter::apply($entry);
            $entry->whereHas('spawn2', function ($query) use ($zone_short) {
                ContentFilter::apply($query);
                $query->where('zone', $zone_short)
                    ->when($this->version > 0, fn ($q) => $q->where('version', $this->version));
            });
            })
            ->whereNotIn('race', [127, 240])
            ->select([
                'id', 'class', 'hp', 'level', 'trackable', 'maxlevel', 'race', 'name',
                'loottable_id', 'raid_target', 'rare_spawn', 'special_abilities',
            ])
            ->get();

        $query2 = NpcType::select([
                'id', 'class', 'hp', 'level', 'trackable', 'maxlevel', 'race', 'name',
                'loottable_id', 'raid_target', 'rare_spawn', 'special_abilities',
            ])
            ->whereNotIn('race', [127, 240])
            ->whereRaw('CAST(SUBSTRING(id, 1, LENGTH(id) - 3) AS UNSIGNED) = ?', [$zone_id])
            ->whereDoesntHave('spawnentries')
            ->get();

        // Zones routinely hold several npc_types sharing a name -- Befallen has
        // eight "a shadowknight" at different levels, races and loot tables --
        // so the list keys on id. Collapsing by name hid the duplicates here
        // and, because drops() reads its loot tables off this list, silently
        // dropped their loot from the Drops tab too. Level breaks the sort tie
        // so the same-named rows read low to high.
        return $query
            ->merge($query2)
            ->unique('id')
            ->sortBy(fn ($npc) => [$npc->clean_name, $npc->level])
            ->values();
    }

    public function drops(): array
    {
        $npcs = $this->npcs();
        $loottableIds = $npcs->pluck('loottable_id')->unique()->filter()->all();

        $items = Item::select([
            'items.id', 'items.Name', 'items.icon', 'items.bagslots',
            ...array_map(fn ($column) => "items.{$column}", ItemCategories::REQUIRED_COLUMNS),
            'loottable_entries.loottable_id',
            ])
            ->join('lootdrop_entries', 'items.id', '=', 'lootdrop_entries.item_id')
            ->join('lootdrop', 'lootdrop_entries.lootdrop_id', '=', 'lootdrop.id')
            ->join('loottable_entries', 'lootdrop_entries.lootdrop_id', '=', 'loottable_entries.lootdrop_id')
            ->join('loottable', 'loottable_entries.loottable_id', '=', 'loottable.id')
            ->whereIn('loottable_entries.loottable_id', $loottableIds)
            ->tap(fn ($q) => ContentFilter::apply($q, 'lootdrop_entries'))
            ->tap(fn ($q) => ContentFilter::apply($q, 'lootdrop'))
            ->tap(fn ($q) => ContentFilter::apply($q, 'loottable'))
            ->groupBy('items.id', 'loottable_entries.loottable_id')
            ->orderBy('items.Name')
            //if ($discovered_items_only) {
                //$items = $items->join('discovered_items', 'items.id', '=', 'discovered_items.item_id');
            //}
            ->get();

        $npcMap = [];
        foreach ($npcs as $npc) {
            if ($npc->loottable_id) {
                $npcMap[$npc->loottable_id][] = $npc;
            }
        }

        $drops = [];
        foreach ($items as $item) {
            if (!isset($drops[$item->id])) {
                $drops[$item->id] = [
                    'item' => $item,
                    'npcs' => [],
                ];
            }

            $drops[$item->id]['npcs'] = array_merge(
                $drops[$item->id]['npcs'],
                $npcMap[$item->loottable_id] ?? []
            );
        }

        uasort($drops, fn($a, $b) => strcasecmp($a['item']->Name, $b['item']->Name));

        return $drops;
    }

    public function spawnGroups(): Collection
    {
        return SpawnTwo::with([
            'spawnGroup.spawnentries' => fn ($q) => ContentFilter::apply($q),
            'spawnGroup.spawnentries.npc' => function ($q) {
                $q->whereNotIn('race', [127, 240]);
            },
        ])
        ->where('zone', $this->zone->short_name)
        ->when($this->version > 0, fn ($q) => $q->where('version', $this->version))
        ->tap(fn ($q) => ContentFilter::apply($q))
        ->whereHas('spawnGroup.spawnentries', function ($q) {
            ContentFilter::apply($q);
            $q->whereHas('npc', fn ($npc) => $npc->whereNotIn('race', [127, 240]));
        })
        ->get()
        ->map(function ($spawn2) {
            $group = $spawn2->spawnGroup;
            $group->x = $spawn2->x;
            $group->y = $spawn2->y;
            $group->z = $spawn2->z;
            $group->respawntime = $spawn2->respawntime;

            return $group;
        })
        ->sortBy('name')
        ->values();
    }

    public function foraged(): Collection
    {
        return Item::whereHas('foraged', function ($query) {
            ContentFilter::apply($query);
            $query->where('zoneid', $this->zone->zoneidnumber);
        })
        ->select('Name', 'id', 'icon', 'bagslots', ...ItemCategories::REQUIRED_COLUMNS)
        ->orderBy('name', 'asc')
        ->get();
    }

    public function fished(): Collection
    {
        return Item::whereHas('fished', function ($query) {
            ContentFilter::apply($query);
            $query->where('zoneid', $this->zone->zoneidnumber);
        })
        ->select('Name', 'id', 'icon', 'bagslots', ...ItemCategories::REQUIRED_COLUMNS)
        ->orderBy('name', 'asc')
        ->get();
    }

    public function tasks(): Collection
    {
        $tasks = Task::whereHas('taskActivities', function ($query) {
            $query->where('zones', $this->zone->zoneidnumber)->where(function ($subQuery) {
                $subQuery->where('zone_version', $this->zone->version)
                    ->orWhere('zone_version', -1);
                });
            })
            ->with(['taskActivities' => function ($query) {
                $query->where('zones', $this->zone->zoneidnumber)->where(function ($subQuery) {
                    $subQuery->where('zone_version', $this->zone->version)
                        ->orWhere('zone_version', -1);
            });
        }])
        ->withCount('taskActivities')
        ->where('min_level', '<=', config('everquest.server_max_level'))
        ->where('enabled', 1)
        ->orderBy('min_level')
        ->get();

        $tasks = Task::attachRewardsMultiple($tasks);

        return $tasks;
    }
}
