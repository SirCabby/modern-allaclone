<?php

namespace App\Filters;

use App\Models\ItemExpansion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ItemFilter
{
    protected $request;
    protected $builder;

    protected array $filters = [
        'name',
        'slot',
        'augslot',
        'type',
        'class',
        'bagslots',
        'effect',
        'focus',
        'click',
        'anystat',
        'evo',
        'expansion',
        'strength',
        /* stats */
        'stat1',
        'stat1comp',
        'stat1val',
        'stat2',
        'stat2comp',
        'stat2val',
        'stat3',
        'stat3comp',
        'stat3val',
    ];

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function apply(Builder $builder): Builder
    {
        $this->builder = $builder;

        foreach ($this->filters as $filter) {
            if (method_exists($this, $filter) && $this->request->filled($filter)) {
                $this->{$filter}($this->request->get($filter));
            }
        }

        $this->applyLevelFilter();

        return $this->builder;
    }

    protected function name($value)
    {
        $this->builder->where('Name', 'like', "%{$value}%");
    }

    /**
     * peq splits a handful of types across two ids that mean the same thing to
     * a player, so picking the one the select offers has to match both.
     */
    protected const COMBINED_TYPES = [
        7  => [7, 19],  // throwing
        56 => [56, 64], // augment distillers
        33 => [33, 39], // keys
    ];

    /** Pseudo-types: bag flavours, told apart by bagtype rather than itemtype. */
    protected const BAG_TYPES = [555, 556, 557];

    /**
     * Illusion is not a category, it is a flag -- and `itemtype` has one slot,
     * so wearing it costs the item whatever it actually was. The Mask of
     * Deception is a face-slot, 4 AC mask that reads as type 69 and therefore
     * is not armour; the Guise of the Deceiver is the same mask with the same
     * click and reads as type 0, 1H Slashing, on an item that does no damage.
     * Neither number describes the item, and between them they do not even
     * agree.
     *
     * So the picker's Illusion entry does not mean `itemtype = 69`. It means
     * the item casts an illusion -- which is checkable, and which the two masks
     * finally answer the same way -- with the flag kept as an OR for the
     * handful whose spell this server does not have. And because the flag is
     * secondary, an illusion item that is worn like armour answers Armour too:
     * the disguise is a thing the mask does, not a thing it is instead of being
     * a mask.
     *
     * Mount (68) is left alone deliberately. It is the same kind of flag, but
     * the items wearing it sit in the ammo slot as keyring entries rather than
     * displacing a real armour slot, so there is no category underneath it to
     * give back.
     */
    protected const TYPE_ILLUSION = 69;

    protected const TYPE_ARMOR = 10;

    /** Spell effect 58 is the illusion itself. */
    protected const SPA_ILLUSION = 58;

    /**
     * Item columns holding a spell the item casts on you. `scrolleffect` is
     * deliberately absent: a scroll that teaches Illusion: Skeleton is a spell
     * to learn, not a disguise to wear, and there are 114 of them.
     */
    protected const ILLUSION_EFFECT_COLUMNS = ['clickeffect', 'worneffect', 'proceffect'];

    /**
     * Where an item goes on the body, which is the one thing about a mistyped
     * item that is never a lie: the Guise of the Deceiver calls itself 1H
     * Slashing and the Mask of Deception calls itself Illusion, and both are
     * face-slot masks with 4 AC and the same click. `slots` says so; neither
     * `itemtype` does.
     *
     * Damage looked like the better test and is not. Plenty of real weapons
     * carry none -- the Combine Scout's Broadsword and the Dwarf Mining Pick
     * are both 0 in peq -- so reading a weapon as "does damage" throws away
     * about 155 of them. What actually separates a sword from a mask is that
     * you hold one and wear the other.
     */
    protected const WIELD_SLOTS = 2048       // range
        | 8192                               // primary
        | 16384                              // secondary
        | 4194304;                           // ammo

    /** The 23 real slots, less the charm slot and the four above. */
    protected const ARMOR_SLOTS = 0x7FFFFF & ~(1 | self::WIELD_SLOTS);

    /** Types that carry no weapon slot honestly. */
    protected const WEAPON_TYPES = [0, 1, 2, 3, 4, 5, 7, 19, 27, 35, 45];

    /**
     * Types whose claim to not be armour is worth re-checking against `slots`:
     * the weapon types, which say nothing true about an item that cannot be
     * held, and the illusion flag, which overwrote whatever was there before.
     * Everything else keeps its own entry in the picker and is left alone --
     * Jewelry and Charm are worn too, and they are not lying about it.
     */
    protected const MISTYPED_ARMOR_TYPES = [...self::WEAPON_TYPES, self::TYPE_ILLUSION];

    /**
     * Item type picker. Multiple ticked types match items of any of them, so
     * everything here goes into one OR group -- ANDing them would return
     * nothing, no item is both a Shield and a Potion.
     */
    protected function type($value)
    {
        $types = collect(is_array($value) ? $value : [$value])
            ->filter(fn ($type) => $type !== null && $type !== '' && is_numeric($type))
            ->map(fn ($type) => (int) $type)
            ->unique()
            ->values()
            ->all();

        if (!$types) {
            return;
        }

        $itemTypes = [];
        $weaponTypes = [];
        $bagTypes = [];
        $illusion = false;

        foreach ($types as $type) {
            if (in_array($type, self::BAG_TYPES, true)) {
                $bagTypes[] = $type;
            } elseif ($type === self::TYPE_ILLUSION) {
                $illusion = true;
            } else {
                foreach (self::COMBINED_TYPES[$type] ?? [$type] as $resolved) {
                    if (in_array($resolved, self::WEAPON_TYPES, true)) {
                        $weaponTypes[] = $resolved;
                    } else {
                        $itemTypes[] = $resolved;
                    }
                }
            }
        }

        // Armour ticked also means the armour a weapon type or the illusion
        // flag is sitting on top of: worn, so listed as worn.
        $mistyped = in_array(self::TYPE_ARMOR, $itemTypes, true);

        // The bag pseudo-types own the bag size box, which is why bagslots()
        // stands aside when one is ticked -- it belongs inside this OR group,
        // not alongside it, or a "Shield or Bags" search would drop every shield.
        $bagSlots = max(1, (int) $this->request->get('bagslots'));

        $this->builder->where(function ($query) use ($itemTypes, $weaponTypes, $bagTypes, $bagSlots, $illusion, $mistyped) {
            if ($itemTypes) {
                $query->orWhereIn('itemtype', array_values(array_unique($itemTypes)));
            }

            // A weapon you cannot hold is not a weapon. Anything with a weapon
            // slot stays, damage or no damage; what drops out is the mask that
            // has only ever gone on your face.
            if ($weaponTypes) {
                $query->orWhere(fn ($q) => $q
                    ->whereIn('itemtype', array_values(array_unique($weaponTypes)))
                    ->whereRaw(
                        '(slots & ? != 0 OR slots & ? = 0)',
                        [self::WIELD_SLOTS, self::ARMOR_SLOTS]
                    ));
            }

            if ($illusion) {
                $query->orWhere(fn ($q) => $this->illusionItems($q));
            }

            if ($mistyped) {
                $query->orWhere(fn ($q) => $q
                    ->whereIn('itemtype', self::MISTYPED_ARMOR_TYPES)
                    ->whereRaw('slots & ? = 0', [self::WIELD_SLOTS])
                    ->whereRaw('slots & ? != 0', [self::ARMOR_SLOTS]));
            }

            foreach ($bagTypes as $bagType) {
                $query->orWhere(function ($bag) use ($bagType, $bagSlots) {
                    $bag->where('bagslots', '>=', $bagSlots);

                    if ($bagType === 555) {
                        $bag->whereIn('bagtype', [0, 1, 2, 3, 4, 5, 6, 7]);
                    } elseif ($bagType === 556) {
                        $bag->where('bagtype', 13);
                    } else {
                        $bag->where('bagtype', '>=', 9)->where('bagtype', '!=', 13);
                    }
                });
            }
        });
    }

    /**
     * "Is an illusion item": casts one, or is flagged as one.
     *
     * Kept as its own bracketed group so callers can AND onto it -- the armour
     * pass narrows this to what is actually worn, and without the nesting the
     * damage check would bind to the last OR arm instead of the whole thing.
     */
    protected function illusionItems($query)
    {
        $spells = $this->illusionSpellIds();

        return $query->where(function ($group) use ($spells) {
            $group->where('itemtype', self::TYPE_ILLUSION);

            foreach (self::ILLUSION_EFFECT_COLUMNS as $column) {
                $group->orWhereIntegerInRaw($column, $spells);
            }
        });
    }

    /**
     * Every spell id that turns you into something else.
     *
     * Effect 58 is the illusion, and it is not always the headline -- Ignite
     * Bones carries it in the second of a spell's twelve effect slots, behind
     * the damage -- so this reads all twelve rather than trusting effectid1.
     *
     * Pulled across and inlined rather than joined, for the same reason
     * expansion() does it: peq is a second connection. One scan of spells_new
     * an hour beats a subquery on every search.
     */
    protected function illusionSpellIds(): array
    {
        return Cache::remember('items.illusion_spells', 3600, fn () => DB::connection('eqemu')
            ->table('spells_new')
            ->where(function ($query) {
                foreach (range(1, 12) as $slot) {
                    $query->orWhere("effectid{$slot}", self::SPA_ILLUSION);
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    protected function bagslots($value)
    {
        if ($value === null || $value === '') {
            return;
        }

        $value = (int) $value;

        // if a custom bag itemtype is ticked, type() has already applied this
        $hasBagType = array_intersect(
            array_map('intval', (array) $this->request->get('type', [])),
            self::BAG_TYPES
        );

        if ($hasBagType) {
            return;
        }

        $this->builder->where('bagslots', '>=', $value);
    }

    protected function slot($value)
    {
        if ($value !== null && is_numeric($value)) {
            $bitmask = (int) $value;

            $this->builder->whereRaw("(slots & ?) != 0", [$bitmask]);
        }
    }

    protected function augslot($value)
    {
        if ($value !== null && is_numeric($value)) {
            $bitmask = 1 << ($value - 1);
            $this->builder->whereRaw("(augtype & ?) != 0", [$bitmask]);
        }
    }

    protected function class($value)
    {
        if ($value !== null && is_numeric($value)) {
            $bitmask = (int) $value;

            // all class.. and any (to fetch without class associated)
            if ($bitmask === 65535) {
                $this->builder->where(function ($q) use ($bitmask) {
                    $q->whereRaw("(classes & ?) != 0", [$bitmask])
                    ->orWhere('classes', 0);
                });
            } else {
                $this->builder->whereRaw("(classes & ?) != 0", [$bitmask]);
            }
        }
    }

    protected function effect($value)
    {
        if ($value === null || $value === '') {
            return;
        }

        $effectRelations = [
            'procEffectSpell',
            'wornEffectSpell',
            'focusEffectSpell',
            'clickEffectSpell',
            'scrollEffectSpell',
        ];

        $this->builder->where(function ($query) use ($value, $effectRelations) {
            foreach ($effectRelations as $relation) {
                $query->orWhereHas($relation, function ($q) use ($value) {
                    $q->where('name', 'like', "%{$value}%")->select('id');
                });
            }
        });
    }

    /** Any focus effect at all, rather than one matching effect(). */
    protected function focus($value)
    {
        $this->hasEffect('focuseffect', $value);
    }

    /** Any clickable effect at all, rather than one matching effect(). */
    protected function click($value)
    {
        $this->hasEffect('clickeffect', $value);
    }

    protected function hasEffect(string $column, $value)
    {
        if (!$value) {
            return;
        }

        $this->effectPresence($this->builder, $column);
    }

    /**
     * Presence test for an effect column. peq stores -1 for "no effect", and a
     * stray row or two carries a spell id past the end of the table; the bounds
     * are the same ones the item page uses to decide whether to draw the
     * effect at all, so the checkbox and the item agree.
     *
     * Takes the query to constrain rather than using the builder directly, so
     * anystat() can fold it into an OR group.
     */
    protected function effectPresence($query, string $column)
    {
        $query->where($column, '>', 0)->where($column, '<', 65535);
    }

    /**
     * Columns that count as the item having a stat. AC is deliberately absent:
     * the point of the filter is to skip plain armour, so an item carrying
     * nothing but AC should not pass. Attack, the regens and the rest of the
     * combat mods are out for the same reason -- they ride along with real
     * stats rather than standing in for them.
     */
    protected const STAT_COLUMNS = [
        'astr', 'asta', 'aagi', 'adex', 'awis', 'aint', 'acha',
        'heroic_str', 'heroic_sta', 'heroic_agi', 'heroic_dex',
        'heroic_wis', 'heroic_int', 'heroic_cha',
        'hp', 'mana', 'endur',
        'mr', 'fr', 'cr', 'dr', 'pr',
        'heroic_mr', 'heroic_fr', 'heroic_cr', 'heroic_dr', 'heroic_pr',
    ];

    /**
     * "Has something on it": any stat, a focus, or a click. One OR group, since
     * an item only needs to satisfy one of them. `!= 0` rather than `> 0` --
     * a penalty is still a stat, and plenty of gear carries one.
     */
    protected function anystat($value)
    {
        if (!$value) {
            return;
        }

        $this->builder->where(function ($query) {
            foreach (self::STAT_COLUMNS as $column) {
                $query->orWhere($column, '!=', 0);
            }

            foreach (['focuseffect', 'clickeffect'] as $column) {
                $query->orWhere(fn ($q) => $this->effectPresence($q, $column));
            }
        });
    }

    protected function evo($value)
    {
        if ($value === null || $value === '') {
            return;
        }

        if ($value == 1) {
            $this->builder->where('evolvinglevel', '>=', 1)
                ->where('evoid', '!=', 0);
        }
    }

    /**
     * The era checklist: keep items whose earliest obtainable era is one of the
     * ticked boxes.
     *
     * The era index is derived data and lives in the app's own sqlite database,
     * so this cannot be a join against peq -- the ids are pulled across and
     * inlined. Worst case is every era ticked, which is ~40k integers; well
     * inside max_allowed_packet, and whereIntegerInRaw skips the bindings.
     */
    protected function expansion($value)
    {
        $eras = collect(is_array($value) ? $value : [$value])
            ->filter(fn ($era) => $era !== null && $era !== '' && is_numeric($era))
            ->map(fn ($era) => (int) $era)
            ->unique()
            ->values()
            ->all();

        if (!$eras) {
            return;
        }

        $this->builder->whereIntegerInRaw('items.id', ItemExpansion::itemIdsForEras($eras));
    }

    /**
     * Consumable strength band. Food and drink keep how filling they are in
     * casttime_ and alcohol its potency, but every other itemtype uses that
     * column for a click's cast time -- so the band carries the consumable
     * restriction with it rather than trusting the type select to be set.
     */
    protected function strength($value)
    {
        $band = config('everquest.consumable_strengths.' . (int) $value);

        if (!$band) {
            return;
        }

        $this->builder->whereIn('itemtype', (array) config('everquest.consumable_types'))
            ->where('casttime_', '>=', $band['min']);

        if ($band['max'] !== null) {
            $this->builder->where('casttime_', '<=', $band['max']);
        }
    }

    protected function stat1()
    {
        $this->applyStat('stat1');
    }

    protected function stat2()
    {
        $this->applyStat('stat2');
    }

    protected function stat3()
    {
        $this->applyStat('stat3');
    }

    protected function applyStat($statKey)
    {
        $stat = $this->request->get($statKey);
        $comp = $this->request->get($statKey . 'comp', 1);
        $val  = $this->request->get($statKey . 'val');

        if ($stat && $val !== null) {

            // fuck operators in url
            $op = match ((int) $comp) {
                1 => '>=',
                2 => '<=',
                5 => '=',
                default => '>='
            };

            if ($stat === 'ratio') {
                $this->builder->whereRaw('(damage > 0 AND delay / damage ' . $op . ' ?)', [$val]);
            } else {
                if ($op === '<=') {
                    $this->builder->where($stat, '>=', 1);
                }

                $this->builder->where($stat, $op, $val);
            }
        }
    }

    protected function applyLevelFilter()
    {
        $maxServerLevel = config('everquest.server_max_level');
        $minLevel = (int) $this->request->get('min_lvl');
        $maxLevel = (int) $this->request->get('max_lvl');

        if ($minLevel > 0 && $maxLevel === 0) {
            $maxLevel = $maxServerLevel;
        }

        if ($maxLevel > 0 && $minLevel === 0) {
            $minLevel = 0;
        }

        $maxLevel = min($maxLevel, $maxServerLevel);

        if ($minLevel > 0 || $maxLevel > 0) {
            $this->builder->whereBetween('reqlevel', [$minLevel, $maxLevel]);
        } else {
            $this->builder->where('reqlevel', '<=', $maxServerLevel);
        }
    }
}
