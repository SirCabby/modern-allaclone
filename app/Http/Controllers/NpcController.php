<?php

namespace App\Http\Controllers;

use App\Filters\NpcFilter;
use App\Models\AlternateCurrency;
use App\Models\DiscoveredItem;
use App\Models\NpcSpell;
use App\Models\NpcType;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\Zone;
use Illuminate\Http\Request;
use App\Support\ContentFilter;
use App\Models\QuestScript;

class NpcController extends Controller
{
    public function index(Request $request)
    {
        $npcs = collect();
        $currentExpansion = ContentFilter::currentExpansion();

        $ignoreZones = config('everquest.ignore_zones') ?? [];
        $zones = Zone::select('id', 'zoneidnumber', 'short_name', 'long_name', 'expansion', 'version')
            ->when(!empty($ignoreZones), function ($q) use ($ignoreZones) {
                $q->whereNotIn('short_name', $ignoreZones);
            })
            ->where('expansion', '<=', $currentExpansion)
            ->orderBy('expansion')
            ->orderBy('long_name')
            ->get()
            ->unique('zoneidnumber')
            ->values();

        if ($request->query->count() > 0) {
            $npcs = (new NpcFilter($request))
                ->apply(NpcType::query())
                ->select('id', 'name', 'level', 'race', 'class', 'hp', 'maxlevel', 'version')
                ->whereNotNull('name')
                ->where('name', '<>', '')
                ->whereNotIn('race', [127, 240])
                ->with([
                    'firstSpawnEntries.spawn2.zoneData',
                ])
                ->orderBy('name', 'asc')
                ->paginate(50)
                ->withQueryString();

            foreach ($npcs as $npc) {
                foreach ($npc->spawnEntries as $entry) {
                    $spawn2 = $entry->spawn2;

                    if (is_object($spawn2) && method_exists($spawn2, 'first')) {
                        $spawn2 = $spawn2->first();
                    }

                    if (!$spawn2) continue;

                    $entry->matched_zone = $zones
                        ->where('short_name', $spawn2->zone)
                        ->where('version', $spawn2->version)
                        ->first();
                }
            }
        }

        return view('npcs.index', [
            'npcs' => $npcs,
            'metaTitle' => config('app.name') . ' - NPC Search',
            'zones' => $zones,
        ]);
    }

    public function show(NpcType $npc)
    {
        $discoveryEnabled = config('everquest.discovered_items.enable');
        $ignoreZones = config('everquest.ignore_zones') ?? [];

        $npc = NpcType::with('npcSpellset.attackProcSpell')
            ->with([
                'spawnEntries.spawn2' => function ($q) use ($ignoreZones) {
                    if (!empty($ignoreZones)) {
                        $q->whereNotIn('zone', $ignoreZones);
                    }

                    $q->with(['npcs' => function ($npcs) {
                        $npcs->select('id', 'name', 'level', 'race', 'class');
                    }]);
                },
                'firstSpawnEntries.spawn2.zoneData',
                'npcFaction.primaryFaction',
                'npcFactionEntries.factionList',
                'lootTable.loottableEntries.lootdropEntries.item',
                'merchantlist.items',
            ])
            ->findOrFail($npc->id);

        if ($npc->npcSpellset) {
            $npc->attackProcSpell = $npc->npcSpellset->attackProcSpell;
            $npc->attackProcSpellProcChance = $npc->npcSpellset->proc_chance;
        }

        $npcSpellset = $npc->npcSpellset;
        if ($npcSpellset && $npcSpellset->parent_list > 0) {
            $npc->npcSpellset = NpcSpell::with('npcSpellEntries.spells', 'attackProcSpell')
                ->where('id', $npcSpellset->parent_list)
                ->first();
        }

        if ($npc->npcSpellset) {
            $npc->filteredSpellEntries = $npc->npcSpellset->npcSpellEntries()
                ->where('minlevel', '<=', $npc->level)
                ->where('maxlevel', '>=', $npc->level)
                ->orderBy('priority', 'desc')
                ->with('spells')
                ->get();
        } else {
            $npc->filteredSpellEntries = collect();
        }

        // separate and group faction
        $raisesFaction = [];
        $lowersFaction = [];

        foreach ($npc->npcFactionEntries as $entry) {
            $factionName = $entry->factionList->name ?? 'Unknown';
            $factionId   = $entry->faction_id;
            $value       = $entry->value;

            if ($value > 0) {
                $raisesFaction[] = [
                    'name' => $factionName,
                    'id' => $factionId,
                    'value' => $value,
                ];
            } elseif ($value < 0) {
                $lowersFaction[] = [
                    'name' => $factionName,
                    'id' => $factionId,
                    'value' => $value,
                ];
            }
        }

        // discovery
        $itemIds = collect();
        if ($discoveryEnabled) {
            if ($npc->lootTable) {
                $itemIds = $itemIds->merge(
                    $npc->lootTable->loottableEntries
                        ->flatMap(function ($entry) {
                            return $entry->lootdropEntries->pluck('item.id');
                        })
                );
            }

            if ($npc->merchantlist) {
                $itemIds = $itemIds->merge($npc->merchantlist->pluck('items.id'));
            }

            $itemIds = $itemIds->filter()->unique()->values();
        }

        $discoveredItems = $discoveryEnabled
            ? DiscoveredItem::whereIn('item_id', $itemIds)->pluck('item_id')->flip()
            : collect();

        // Quest scripts are indexed from the server's quests/ tree, not peq.
        $questScripts = QuestScript::forNpc($npc->id)
            ->with(['items.item', 'tasks.task'])
            ->get();

        // Tasks those scripts drive, deduped across scripts by strongest kind
        // (a task offered by one script and referenced by another is an offer).
        // Only scripts this NPC owns count: forNpc() also returns scripts that
        // merely spawn the NPC, and a kill target must not inherit the offers
        // of whatever script spawns it.
        $rank = ['offer' => 3, 'update' => 2, 'mentioned' => 1];
        $ownScripts = $questScripts->filter(fn ($s) => (int) $s->npc_id === (int) $npc->id);
        $scriptTasks = $ownScripts
            ->flatMap->tasks
            ->filter(fn ($ref) => $ref->task)
            ->sortByDesc(fn ($ref) => $rank[$ref->kind] ?? 0)
            ->unique('task_id')
            ->values();

        // The reverse tie: tasks that name this NPC as an objective. Scripted
        // relationships above are richer, so only tasks with no script link
        // remain here (a kill target has no script of its own).
        $taskObjectives = $this->taskObjectives($npc)
            ->reject(fn ($obj) => $scriptTasks->contains('task_id', $obj->task->id))
            ->values();

        // One quest per task, plus one per script that contributed no task rows
        // (classic hand-in quests, and scripts that only reference this NPC).
        $questCount = $scriptTasks->count()
            + $taskObjectives->count()
            + $questScripts->count()
            - $ownScripts->filter(fn ($s) => $s->tasks->isNotEmpty())->count();

        $defaultTab = null;
        if ($npc->lootTable?->loottableEntries->isNotEmpty()) {
            $defaultTab = 'drops';
        } elseif ($npc->merchantlist->isNotEmpty()) {
            $defaultTab = 'merchant';
        } elseif ($npc->spawnEntries->isNotEmpty()) {
            $defaultTab = 'spawns';
        } elseif ($questScripts->isNotEmpty() || $taskObjectives->isNotEmpty()) {
            $defaultTab = 'quests';
        } elseif ($npc->npcFactionEntries->isNotEmpty()) {
            $defaultTab = 'faction';
        }

        $lvl = $npc->level ? ' - Level (' . $npc->level . ')' : '';

        $altCurrency = AlternateCurrency::allAltCurrency();

        return view('npcs.show', [
            'npc' => $npc,
            'defaultTab' => $defaultTab,
            'raisesFaction' => $raisesFaction,
            'lowersFaction' => $lowersFaction,
            'altCurrency' => $altCurrency,
            'discoveredItems' => $discoveredItems,
            'questScripts' => $questScripts,
            'scriptTasks' => $scriptTasks,
            'taskObjectives' => $taskObjectives,
            'questCount' => $questCount,
            'metaTitle' => config('app.name') . ' - NPC: ' . $npc->clean_name . $lvl,
        ]);
    }

    /**
     * Tasks whose activities target this NPC (kill, speak with, deliver...).
     * task_activities matches NPCs by id or name fragment in npc_match_list, or
     * by display name in target_name (see TaskActivity::getNpcsAttribute); this
     * runs that matching in reverse, in PHP -- the table is ~2k short rows.
     */
    private function taskObjectives(NpcType $npc)
    {
        $activities = TaskActivity::query()
            ->where(function ($q) {
                $q->where('npc_match_list', '<>', '')
                    ->orWhere('target_name', '<>', '');
            })
            ->get(['taskid', 'activityid', 'activitytype', 'target_name', 'npc_match_list']);

        $clean = $npc->clean_name;

        $matched = $activities->filter(function ($a) use ($npc, $clean) {
            foreach (explode('|', (string) $a->npc_match_list) as $entry) {
                $entry = trim($entry);
                // Entries made of LIKE wildcards ('_') mean "kill anything in
                // the activity's zones" -- that targets no NPC in particular.
                if ($entry === '' || trim($entry, '_%') === '') {
                    continue;
                }
                if (is_numeric($entry)) {
                    if ((int) $entry === (int) $npc->id) {
                        return true;
                    }
                } elseif (stripos($npc->name, $entry) !== false || stripos($clean, $entry) !== false) {
                    return true;
                }
            }

            $target = trim((string) $a->target_name);

            return $target !== '' && strcasecmp($target, $clean) === 0;
        });

        if ($matched->isEmpty()) {
            return collect();
        }

        $tasks = Task::whereIn('id', $matched->pluck('taskid')->unique())
            ->where('enabled', 1)
            ->get(['id', 'title', 'type'])
            ->keyBy('id');

        return $matched
            ->groupBy('taskid')
            ->map(function ($acts, $taskId) use ($tasks) {
                $task = $tasks->get((int) $taskId);
                if (!$task) {
                    return null;
                }

                return (object) [
                    'task' => $task,
                    'types' => $acts->pluck('activitytype')->unique()
                        ->map(fn ($t) => config('everquest.task_activity_types.' . $t))
                        ->filter()->unique()->values(),
                ];
            })
            ->filter()
            ->sortBy(fn ($obj) => $obj->task->title)
            ->values();
    }
}
