<?php

namespace App\Http\Controllers;

use App\Filters\AaAbilityFilter;
use App\Models\AaAbility;
use App\Models\AaRank;
use App\Models\Spell;
use App\Models\Zone;
use App\Services\AaRankService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AaAbilityController extends Controller
{
    protected $allSpells;
    protected $allZones;

    public function __construct()
    {
        $this->allSpells = Cache::rememberForever('all_spells', function () {
            return Spell::pluck('name', 'id');
        });

        $this->allZones = Cache::rememberForever('all_zones', function () {
            return Zone::select('id', 'short_name', 'long_name', 'expansion')
                ->orderBy('id')
                ->get()
                ->unique('short_name')
                ->keyBy('short_name');
        });

        view()->share('allSpells', $this->allSpells);
        view()->share('allZones', $this->allZones);
    }

    public function index(Request $request)
    {
        $query = AaAbility::sortable(['name' => 'asc']);

        $abilities = (new AaAbilityFilter($request))
            ->apply($query)
            ->where('enabled', 1)
            ->paginate(25)
            ->withQueryString();

        $allAbilities = AaAbility::orderBy('name')
            ->where('enabled', 1)
            ->get();

        return view('aa.index', [
            'abilities' => $abilities,
            'allAbilities' => $allAbilities,
            'metaTitle' => config('app.name') . ' - AA Search',
        ]);
    }

    public function show(AaAbility $ability, AaRankService $rankService)
    {
        $ids = [];

        $current = $ability->firstRank()
            ->select('id', 'next_id')
            ->first();

        while ($current) {
            $ids[] = $current->id;

            if (!$current->next_id) {
                break;
            }

            $current = AaRank::query()
                ->select('id', 'next_id')
                ->where('id', $current->next_id)
                ->first();
        }

        $ranks = AaRank::whereIn('id', $ids)
            ->with([
                'effects',
                'prereqs.ability',
                'spell_',
            ])
            ->get()
            ->keyBy('id');

        $orderedRanks = collect();
        $currentId = $ability->first_rank_id;

        while ($currentId) {
            $rank = $ranks[$currentId] ?? null;

            if (!$rank) {
                break;
            }

            $orderedRanks->push($rank);
            $currentId = $rank->next_id;
        }

        $rankCount = $orderedRanks->count();
        $presentedRanks = $orderedRanks->map(fn($r) => $rankService->presentRank($r));

        $allAbilities = AaAbility::orderBy('name')
            ->where('enabled', 1)
            ->get();

        return view('aa.show', [
            'ability' => $ability,
            'allRanks' => $presentedRanks,
            'rankCount' => $rankCount,
            'allAbilities' => $allAbilities,
        ]);
    }
}
