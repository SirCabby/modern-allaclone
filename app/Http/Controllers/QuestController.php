<?php

namespace App\Http\Controllers;

use App\Filters\QuestFilter;
use App\Models\NpcType;
use App\Models\QuestScript;
use App\Models\Zone;
use App\Support\ContentFilter;
use Illuminate\Http\Request;

class QuestController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'name' => 'nullable|string|max:100',
            'zone' => 'nullable|string|max:64',
            'language' => 'nullable|in:pl,lua',
        ]);

        $zones = $this->liveZones();
        $liveZones = $zones->pluck('short_name')->push('global')->all();

        $quests = (new QuestFilter($request))
            ->apply(QuestScript::query())
            ->whereIn('zone', $liveZones)
            ->withCount([
                'items as handin_count' => fn ($q) => $q->where('kind', 'handin'),
                'items as reward_count' => fn ($q) => $q->where('kind', 'reward'),
                'npcs',
            ])
            ->orderBy('zone')
            ->orderBy('file_name')
            ->paginate(50)
            ->withQueryString();

        return view('quests.index', [
            'quests' => $quests,
            'zones' => $zones,
            'zoneNames' => $zones->pluck('long_name', 'short_name'),
            'metaTitle' => config('app.name') . ' - Quest Search',
        ]);
    }

    public function show(QuestScript $quest)
    {
        $quest->load(['items.item', 'npcs.npc', 'tasks.task']);

        // The NPC that owns the script; peq lives on another connection so this
        // is a straight lookup, not a relation.
        $npc = $quest->npc_id
            ? NpcType::select('id', 'name', 'level')->find($quest->npc_id)
            : null;

        $zone = $quest->zone !== 'global'
            ? Zone::select('id', 'short_name', 'long_name')
                ->where('short_name', $quest->zone)
                ->orderBy('version')
                ->first()
            : null;

        // Other scripts the same NPC drives (multi-part quest chains).
        $siblings = $quest->npc_id
            ? QuestScript::where('npc_id', $quest->npc_id)
                ->where('id', '<>', $quest->id)
                ->orderBy('zone')
                ->orderBy('file_name')
                ->get()
            : collect();

        return view('quests.show', [
            'zones' => $this->liveZones(),
            'quest' => $quest,
            'npc' => $npc,
            'zone' => $zone,
            'siblings' => $siblings,
            'handins' => $quest->items->where('kind', 'handin'),
            'rewards' => $quest->items->where('kind', 'reward'),
            'mentions' => $quest->items->where('kind', 'mentioned'),
            'metaTitle' => config('app.name') . ' - Quest: ' . $quest->display_name,
        ]);
    }

    /**
     * In-era zones for the search dropdown and the index's era gate, so quests
     * for zones the server has not opened yet stay hidden like everything else.
     */
    private function liveZones()
    {
        $ignoreZones = config('everquest.ignore_zones') ?? [];

        return Zone::select('id', 'short_name', 'long_name', 'expansion')
            ->when(!empty($ignoreZones), function ($q) use ($ignoreZones) {
                $q->whereNotIn('short_name', $ignoreZones);
            })
            ->tap(fn ($q) => ContentFilter::applyZone($q))
            ->orderBy('long_name')
            ->get()
            ->unique('short_name')
            ->values();
    }
}
