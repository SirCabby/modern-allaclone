<?php

namespace App\Models;

use App\Support\ContentFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
use Kyslik\ColumnSortable\Sortable;

class NpcType extends Model
{
    use HasFactory;
    use Sortable;

    public array $sortable = [
        'name',
        'level',
        'hp',
        // not a column: see zoneSortable()
        'zone',
    ];

    protected $connection = 'eqemu';
    protected $table = 'npc_types';

    /**
     * Order by the zone the search shows for the NPC.
     *
     * That name is three tables away -- spawnentry -> spawn2 -> zone -- and an
     * NPC with four spawn points is still one row, so this is a correlated
     * subquery rather than a join, gated the way the page gates the column
     * itself. NPCs with no live spawn point (quest- or script-spawned) show an
     * empty cell, so they sit at the bottom whichever way the column points.
     */
    public function zoneSortable($query, $direction)
    {
        $ignoreZones = config('everquest.ignore_zones') ?? [];

        $zone = DB::connection($this->getConnectionName())
            ->table('spawnentry')
            ->select('zone.long_name')
            ->join('spawn2', 'spawn2.spawngroupID', '=', 'spawnentry.spawngroupID')
            ->join('zone', function ($join) {
                $join->on('zone.short_name', '=', 'spawn2.zone')
                    ->on('zone.version', '=', 'spawn2.version');
            })
            ->whereColumn('spawnentry.npcID', 'npc_types.id')
            ->when(!empty($ignoreZones), fn ($q) => $q->whereNotIn('spawn2.zone', $ignoreZones))
            ->tap(fn ($q) => ContentFilter::apply($q, 'spawnentry'))
            ->tap(fn ($q) => ContentFilter::apply($q, 'spawn2'))
            ->tap(fn ($q) => ContentFilter::applyZone($q, 'zone'))
            ->orderBy('zone.long_name')
            ->limit(1);

        return $query
            ->orderByRaw('(' . $zone->toSql() . ') IS NULL', $zone->getBindings())
            ->orderBy($zone, $direction);
    }

    public function getCleanNameAttribute(): string
    {
        return $this->npcFixName($this->name);
    }

    public function getParsedSpecialAbilitiesAttribute()
    {
        $raw = $this->special_abilities;
        if (!$raw) {
            return [];
        }

        $attacks = explode('^', $raw);
        $labels = [];

        foreach ($attacks as $entry) {
            $parts = explode(',', $entry);
            $id = intval($parts[0]);
            $label = config('everquest.special_attacks.' . $id);

            if ($label) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    public function firstSpawnEntries(): HasOne
    {
        $ignoreZones = config('everquest.ignore_zones') ?? [];

        return $this->hasOne(SpawnEntry::class, 'npcID', 'id')
            ->whereHas('spawn2', function ($q) use ($ignoreZones) {
                if (!empty($ignoreZones)) {
                    $q->whereNotIn('zone', $ignoreZones);
                }
            })
            ->orderBy('spawngroupID');
    }

    public function spawnEntries(): HasMany
    {
        return $this->hasMany(SpawnEntry::class, 'npcID', 'id');
    }

    public function lootTable(): HasOne
    {
        return $this->hasOne(LootTable::class, 'id', 'loottable_id');
    }

    public function loottableEntries(): HasMany
    {
        return $this->hasMany(LoottableEntry::class, 'loottable_id', 'loottable_id');
    }

    public function merchantlist(): HasMany
    {
        return $this->hasMany(Merchantlist::class, 'merchantid', 'merchant_id');
    }

    public function npcSpellset(): HasOne
    {
        return $this->hasOne(NpcSpell::class, 'id', 'npc_spells_id')
            ->select('id', 'name', 'parent_list', 'attack_proc', 'proc_chance');
    }

    public function npcFaction(): BelongsTo
    {
        return $this->belongsTo(NpcFaction::class, 'npc_faction_id', 'id');
    }

    public function npcFactionEntries(): HasMany
    {
        return $this->hasMany(NpcFactionEntry::class, 'npc_faction_id', 'npc_faction_id');
    }

    public function lootDrops(): HasManyThrough
    {
        return $this->hasManyThrough(
            LootdropEntry::class,
            LoottableEntry::class,
            'loottable_id',
            'lootdrop_id',
            'loottable_id',
            'lootdrop_id'
        );
    }

    public static function npcFixName(string $npc): string
    {
        $name = str_replace(['#', '!', '~'], '', $npc);
        $name = str_replace('_', ' ', $name);
        $name = str_replace('-', '`', $name);
        $name = preg_replace('/\d/', '', $name);

        return $name;
    }
}
