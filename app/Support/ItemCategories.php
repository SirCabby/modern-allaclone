<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Which categories an item actually belongs to, as opposed to the one number
 * `items.itemtype` has room for.
 *
 * peq's types are dirty in two different directions and the same pair of masks
 * shows both. The Mask of Deception and the Guise of the Deceiver are the same
 * face-slot, 4 AC mask casting the same spell; one calls itself Illusion and so
 * stops being armour, the other calls itself 1H Slashing on an item that does
 * no damage. Neither number describes the item and between them they do not
 * even agree.
 *
 * So an item gets a *list*:
 *
 *   primary     what it is -- corrected against `slots` where the type is a
 *               weapon category on something that cannot be held
 *   secondary   what it also does. Illusion is only ever this: a disguise is
 *               something a mask does, never something it is instead of being
 *               a mask.
 *
 * This is the one definition. ItemFilter turns it into SQL so the picker
 * matches on it, and the results table reads it per row so the Type column
 * shows the same answer the search just used -- previously the filter had been
 * taught all of this and the column was still printing the raw number.
 */
class ItemCategories
{
    public const TYPE_ARMOR = 10;

    public const TYPE_ILLUSION = 69;

    /** Spell effect 58 is the illusion itself. */
    public const SPA_ILLUSION = 58;

    /**
     * Item columns holding a spell the item casts on you. `scrolleffect` is
     * deliberately absent: a scroll that teaches Illusion: Skeleton is a spell
     * to learn, not a disguise to wear, and there are 114 of them.
     */
    public const ILLUSION_EFFECT_COLUMNS = ['clickeffect', 'worneffect', 'proceffect'];

    /**
     * Where an item goes on the body, which is the one thing a mistyped item is
     * never wrong about.
     *
     * Damage looked like the better test and is not. Plenty of real weapons
     * carry none -- the Combine Scout's Broadsword and the Dwarf Mining Pick
     * are both 0 in peq -- so reading a weapon as "does damage" throws away
     * about 155 of them. What separates a sword from a mask is that you hold
     * one and wear the other.
     */
    public const WIELD_SLOTS = 2048          // range
        | 8192                               // primary
        | 16384                              // secondary
        | 4194304;                           // ammo

    /** The 23 real slots, less the charm slot and the four above. */
    public const ARMOR_SLOTS = 0x7FFFFF & ~(1 | self::WIELD_SLOTS);

    /** Types that say nothing true about an item with no weapon slot. */
    public const WEAPON_TYPES = [0, 1, 2, 3, 4, 5, 7, 19, 27, 35, 45];

    /**
     * Types worth re-checking against `slots`: the weapon types above, and the
     * illusion flag, which overwrote whatever was there before. Everything else
     * keeps its own entry and is left alone -- Jewelry and Charm are worn too,
     * and they are not lying about it.
     */
    public const MISTYPED_ARMOR_TYPES = [...self::WEAPON_TYPES, self::TYPE_ILLUSION];

    /**
     * What a row needs on it before labels() can answer honestly.
     *
     * Selecting a subset does not fail, it quietly degrades: with no `slots` a
     * mask cannot be shown to be worn, and with no effect columns an illusion
     * goes unnoticed -- both of which land back on the raw itemtype, which is
     * the bug this class exists to fix. So anything rendering a category pulls
     * these, and does it from here rather than by remembering to.
     */
    public const REQUIRED_COLUMNS = ['itemtype', 'slots', ...self::ILLUSION_EFFECT_COLUMNS];

    private const ILLUSION_CACHE_KEY = 'items.illusion_spells';

    /**
     * Every spell id that turns you into something else.
     *
     * Effect 58 is the illusion, and it is not always the headline -- Ignite
     * Bones carries it in the second of a spell's twelve effect slots, behind
     * the damage -- so this reads all twelve rather than trusting effectid1.
     *
     * Pulled across and inlined by callers rather than joined, because peq is a
     * second connection. One scan of spells_new an hour beats a subquery on
     * every search.
     */
    public static function illusionSpellIds(): array
    {
        return Cache::remember(self::ILLUSION_CACHE_KEY, 3600, fn () => DB::connection('eqemu')
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

    /** Worn on the body rather than held in a hand. */
    public static function isWorn(object $item): bool
    {
        $slots = (int) ($item->slots ?? 0);

        return ($slots & self::WIELD_SLOTS) === 0 && ($slots & self::ARMOR_SLOTS) !== 0;
    }

    /**
     * Whether the item itself casts an illusion.
     *
     * Falls back to the flag for the five items whose spell this server does
     * not have -- and returns false, rather than guessing, when the caller
     * selected no effect columns to read.
     */
    public static function castsIllusion(object $item): bool
    {
        if ((int) ($item->itemtype ?? -1) === self::TYPE_ILLUSION) {
            return true;
        }

        $spells = array_flip(self::illusionSpellIds());

        foreach (self::ILLUSION_EFFECT_COLUMNS as $column) {
            if (isset($item->{$column}, $spells[(int) $item->{$column}])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The category the item really belongs in.
     *
     * Only the types that are demonstrably lying get corrected, and only
     * towards armour, which is the one thing `slots` can prove. A type-69 item
     * that is not worn keeps Illusion as its primary because there is nothing
     * underneath it to recover -- the keyring entries in the ammo slot are the
     * usual case.
     */
    public static function primaryType(object $item): int
    {
        $type = (int) ($item->itemtype ?? 0);

        if (in_array($type, self::MISTYPED_ARMOR_TYPES, true) && self::isWorn($item)) {
            return self::TYPE_ARMOR;
        }

        return $type;
    }

    /**
     * Every category the item answers to, primary first, as labels.
     *
     * @return string[]
     */
    public static function labels(object $item): array
    {
        $types = config('everquest.item_types');
        $primary = self::primaryType($item);

        $labels = [$types[$primary] ?? "Type {$primary}"];

        if ($primary !== self::TYPE_ILLUSION && self::castsIllusion($item)) {
            $labels[] = $types[self::TYPE_ILLUSION];
        }

        return $labels;
    }
}
