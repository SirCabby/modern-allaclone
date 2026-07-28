<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Zone;
use App\Models\Spell;
use App\Models\NpcType;
use App\Models\FactionList;
use App\Models\QuestScript;
use App\Models\Task;
use App\Models\TradeskillRecipe;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function suggest(Request $request)
    {
        $discoveryEnabled = config('everquest.discovered_items.enable');
        $q = $request->query('q');

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        // create a special query for npc type names
        $qNpcs = str_replace(' ', '_', $q);
        $qNpcs = str_replace('`', '-', $qNpcs);
        $qId = $q;

        $results = collect();

        $results = $results
            ->merge(
                NpcType::where('name', 'like', "%{$q}%")->orWhere('name', 'like', "%{$qNpcs}%")
                    ->orWhereRaw('CAST(id AS CHAR) LIKE ?', ["%{$qId}%"])
                    ->groupBy('name')->limit(5)->get()->map(function ($npc) {
                        return [
                            'type' => 'npc',
                            'name' => $npc->clean_name,
                            'url' => route('npcs.show', $npc->id),
                            'id' => 'npc-' . $npc->id
                        ];
                    })
            )->merge(
                Item::query()
                    ->when($discoveryEnabled, function ($q) {
                        $q->whereHas('discovery');
                    })
                    ->where(function ($qBuilder) use ($q, $qId) {
                        $qBuilder->where('Name', 'like', "%{$q}%")
                            ->orWhereRaw('CAST(id AS CHAR) LIKE ?', ["%{$qId}%"]);
                    })
                    ->limit(10)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'type' => 'item',
                            'name' => $item->Name,
                            'url' => route('items.show', $item->id),
                            'id' => 'item-' . $item->id
                        ];
                    })
            )->merge(
                TradeskillRecipe::where('name', 'like', "%{$q}%")->limit(5)->get()->map(function ($r) {
                    return [
                        'type' => 'recipe',
                        'name' => $r->name,
                        'url' => route('recipes.show', $r->id),
                        'id' => 'recipe-' . $r->id
                    ];
                })
            )->merge(
                Zone::where(function ($query) use ($q, $qId) {
                    $query->where('long_name', 'like', "%{$q}%")
                        ->orWhere('short_name', 'like', "%{$q}%")
                        ->orWhereRaw('CAST(zoneidnumber AS CHAR) LIKE ?', ["%{$qId}%"]);
                })
                    ->whereNotIn('short_name', config('everquest.ignore_zones', []))
                    ->groupBy('short_name', 'long_name')->limit(5)->get()->map(function ($z) {
                        return [
                            'type' => 'zone',
                            'name' => $z->long_name,
                            'url' => route('zones.show', $z->id),
                            'id' => 'zone-' . $z->id
                        ];
                    })
            )->merge(
                FactionList::where('name', 'like', "%{$q}%")->limit(5)->get()->map(function ($f) {
                    return [
                        'type' => 'faction',
                        'name' => $f->name,
                        'url' => route('factions.show', $f->id),
                        'id' => 'faction-' . $f->id
                    ];
                })
            )->merge(
                Spell::where('name', 'like', "%{$q}%")->orWhereRaw('CAST(id AS CHAR) LIKE ?', ["%{$qId}%"])
                    ->groupBy('name')->limit(5)->get()->map(function ($s) {
                        return [
                            'type' => 'spell',
                            'name' => $s->name,
                            'url' => route('spells.show', $s->id),
                            'id' => 'spell-' . $s->id
                        ];
                    })
            )->merge(
                // Quest scripts use npc_types spellings ('_' for spaces), so
                // reuse the NPC-shaped query string.
                QuestScript::where(function ($builder) use ($q, $qNpcs) {
                    $builder->where('npc_name', 'like', "%{$qNpcs}%")
                        ->orWhere('file_name', 'like', "%{$q}%");
                })->limit(5)->get()->map(function ($qs) {
                    return [
                        'type' => 'quest',
                        'name' => $qs->display_name . ' (' . $qs->zone . ')',
                        'url' => route('quests.show', $qs->id),
                        'id' => 'quest-' . $qs->id
                    ];
                })
            )->merge(
                // Tasks are what players know as quest names ("Pit Fiend
                // (Group)"); their routes 404 when tasks are disabled, so gate
                // the suggestions the same way as TasksEnabled.
                !config('everquest.tasks.enable', true) ? collect() :
                Task::where('title', 'like', "%{$q}%")
                    ->where('enabled', 1)
                    ->limit(5)->get()->map(function ($t) {
                        return [
                            'type' => 'task',
                            'name' => $t->title,
                            'url' => route('tasks.show', $t->id),
                            'id' => 'task-' . $t->id
                        ];
                    })
            );

        return response()->json($results->take(40)->values());
    }
}
