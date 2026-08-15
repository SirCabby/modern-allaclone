<?php

namespace App\Http\Controllers;

use App\Models\FactionList;
use App\Models\NpcType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FactionController extends Controller
{
    public function index(Request $request)
    {
        $factions = Cache::rememberForever('factions.all', function () {
            return FactionList::orderBy('name', 'asc')->get();
        });

        return view('factions.index', [
            'factions' => $factions,
            'metaTitle' => config('app.name') . ' - Factions',
        ]);
    }

    public function show(FactionList $faction)
    {
        // all factions for select
        $allFactions = Cache::rememberForever('factions.all', function () {
            return FactionList::orderBy('name', 'asc')->get();
        });

        // Listing every npc_type rather than one per name doubles what this
        // hydrates -- Heretics alone reaches 27k NPCs over 63k spawn entries --
        // and all the page wants out of that chain is the zone's name and id.
        // Constraining the columns keeps the wide zone and spawn2 rows out of
        // memory; the join keys have to stay in the select or the relations
        // cannot match up.
        $npcs = NpcType::with([
            'npcFactionEntries' => function ($q) use ($faction) {
                $q->where('faction_id', $faction->id)
                    ->select('npc_faction_id', 'faction_id', 'value');
            },
            'spawnEntries:npcID,spawngroupID',
            'spawnEntries.spawn2:spawngroupID,zone',
            'spawnEntries.spawn2.zoneData:id,short_name,long_name',
        ])
            ->whereHas('npcFactionEntries', function ($q) use ($faction) {
                $q->where('faction_id', $faction->id);
            })
            ->select('id', 'name', 'npc_faction_id')
            ->get()
            ->unique('id')
            ->sortBy(function ($npc) {
                foreach ($npc->spawnEntries as $se) {
                    $s2 = $se->spawn2;
                    if (is_object($s2) && method_exists($s2, 'first')) {
                        $s2 = $s2->first();
                    }

                    if ($s2 && $s2->zoneData) {
                        return $s2->zoneData->long_name;
                    }
                }

                return '';
        });

        $factions = [
            'raised' => collect(),
            'lowered' => collect(),
        ];

        // Several npc_types share a name -- Befallen alone has eight "a
        // shadowknight" -- and grouping the query by name kept exactly one of
        // them for the whole faction, so the same name hit in a second zone
        // vanished from that zone's list. The list is per zone, and a row only
        // says name and value, so the collapse belongs here: identical rows
        // inside one zone fold together, the same name in another zone stays.
        $seen = [];

        foreach ($npcs as $npc) {
            foreach ($npc->npcFactionEntries as $entry) {
                $value = (int) $entry->value;
                if ($value === 0) {
                    continue;
                }

                $zoneId = null;
                $zoneName = 'Unknown Zone';

                foreach ($npc->spawnEntries as $se) {
                    $s2 = $se->spawn2;
                    if (is_object($s2) && method_exists($s2, 'first')) {
                        $s2 = $s2->first();
                    }

                    if ($s2 && $s2->zoneData) {
                        $zoneId = $s2->zoneData->id;
                        $zoneName = $s2->zoneData->long_name;
                        break;
                    }
                }

                $type = $value > 0 ? 'raised' : 'lowered';
                $zoneKey = $zoneId . '|' . $zoneName;
                $rowKey = $zoneKey . '|' . $npc->clean_name . '|' . $value;

                if (isset($seen[$rowKey])) {
                    continue;
                }

                $seen[$rowKey] = true;

                $factions[$type]->push([
                    'zone_key' => $zoneKey,
                    'zone' => $zoneName,
                    'zone_id' => $zoneId,
                    'npc_id' => $npc->id,
                    'npc_name' => $npc->clean_name,
                    'value' => $value,
                ]);
            }
        }

        $factions['raised'] = $factions['raised']->groupBy('zone_key')->map(function ($npcs) {
            return $npcs->sortBy('npc_name');
        });

        $factions['lowered'] = $factions['lowered']->groupBy('zone_key')->map(function ($npcs) {
            return $npcs->sortBy('npc_name');
        });

        return view('factions.show', [
            'allFactions' => $allFactions,
            'faction' => $faction,
            'factions' => $factions,
            'metaTitle' => config('app.name') . ' - Faction: ' . $faction->name,
        ]);
    }
}
