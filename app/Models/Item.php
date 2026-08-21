<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kyslik\ColumnSortable\Sortable;

class Item extends Model
{
    use HasFactory;
    use Sortable;

    public array $sortable = [
        'Name',
        'itemtype',
        'ac',
        'hp',
        'damage',
        'ratio',
        'potency',
        // 1 and 3 are the inventory clicks, 4 and 5 the worn ones, so the raw
        // column already sorts the two groups apart.
        'clicktype',
        // dynamic columns from stats#comp fields.
        'mana',
        'endur',
        'haste',
        'aagi',
        'acha',
        'adex',
        'aint',
        'asta',
        'astr',
        'awis',
        'heroic_agi',
        'heroic_cha',
        'heroic_dex',
        'heroic_int',
        'heroic_sta',
        'heroic_str',
        'heroic_wis',
        'attack',
        'delay',
        'range',
        'regen',
        'manaregen',
        'enduranceregen',
        'spellshield',
        'combateffects',
        'shielding',
        'damageshield',
        'dotshielding',
        'dsmitigation',
        'avoidance',
        'accuracy',
        'stunresist',
        'strikethrough',
        'spelldmg',
    ];

    protected $connection = 'eqemu';
    protected $table = 'items';

    public function procEffectSpell(): BelongsTo
    {
        return $this->belongsTo(Spell::class, 'proceffect', 'id')->select('id', 'name', 'new_icon');
    }

    public function wornEffectSpell(): BelongsTo
    {
        return $this->belongsTo(Spell::class, 'worneffect', 'id')->select('id', 'name', 'new_icon');
    }

    public function focusEffectSpell(): BelongsTo
    {
        return $this->belongsTo(Spell::class, 'focuseffect', 'id')->select('id', 'name', 'new_icon');
    }

    public function clickEffectSpell(): BelongsTo
    {
        return $this->belongsTo(Spell::class, 'clickeffect', 'id')->select('id', 'name', 'new_icon');
    }

    public function scrollEffectSpell(): BelongsTo
    {
        return $this->belongsTo(Spell::class, 'scrolleffect', 'id')->select('id', 'name', 'new_icon');
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchantlist::class, 'item', 'id');
    }

    public function drops(): HasMany
    {
        return $this->hasMany(LootdropEntry::class, 'item_id', 'id');
    }

    public function foraged(): HasMany
    {
        return $this->hasMany(Forage::class, 'itemid', 'id');
    }

    public function fished(): HasMany
    {
        return $this->hasMany(Fishing::class, 'itemid', 'id');
    }

    public function lootdropEntries(): HasMany
    {
        return $this->hasMany(LootdropEntry::class, 'item_id', 'id');
    }

    public function evolvingDetails(): HasMany
    {
        return $this->hasMany(ItemEvolvingDetail::class, 'item_evo_id', 'evoid')->orderBy('item_evolve_level');
    }

    public function discovery(): HasOne
    {
        return $this->hasOne(DiscoveredItem::class, 'item_id', 'id');
    }

    public function ratioSortable($query, $direction)
    {
        $raw = "CASE WHEN damage = 0 THEN 1e9 ELSE (delay / NULLIF(damage,0)) END";

        return $query->orderByRaw($raw . ' ' . $direction)->select('items.*');
    }

    /**
     * Food/drink/alcohol strength, which lives in casttime_ -- a column every
     * other itemtype uses for a click's cast time, so the type check carries
     * the restriction rather than trusting the search to have narrowed it.
     * Items without one sort last in both directions: a page of dashes at the
     * top is not a result either way.
     */
    public function potencySortable($query, $direction)
    {
        $types = implode(',', array_map('intval', (array) config('everquest.consumable_types')));
        $raw = "CASE WHEN itemtype IN ({$types}) AND casttime_ > 0 THEN casttime_ END";

        return $query->orderByRaw("{$raw} IS NULL, {$raw} {$direction}");
    }

    public function isDiscovered(): bool
    {
        return $this->relationLoaded('discovery')
            ? $this->discovery !== null
            : $this->discovery()->exists();
    }

    public function canDisplay(): bool
    {
        if (!config('everquest.discovered_items.enable')) {
            return true;
        }

        return $this->isDiscovered();
    }
}
