<?php

namespace App\Support;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the server's own content gating so the site shows what players can
 * actually find, rather than everything the schema happens to contain.
 *
 * This is a direct port of ContentFilterCriteria::apply() from the emulator
 * source (common/repositories/criteria/content_filter_criteria.h):
 *
 *   AND (min_expansion <= X OR min_expansion = -1)
 *   AND (max_expansion >= X OR max_expansion = -1)
 *   AND (content_flags IS NULL OR content_flags = '' OR <matches an enabled flag>)
 *   AND (content_flags_disabled IS NULL OR content_flags_disabled = ''
 *        OR <matches a disabled flag>)
 *
 * X comes from the live Expansion:CurrentExpansion rule, so bumping the era
 * in-game is reflected here without a redeploy.
 */
class ContentFilter
{
    /** Sentinel meaning "ignore expansion gating entirely". */
    public const ALL = -999;

    /** Session key used by the era switcher. */
    public const SESSION_KEY = 'eqemu_era';

    private const CACHE_TTL = 60; // seconds; keeps `#reload rules` visible quickly

    /**
     * The expansion the site is currently presenting.
     *
     * Resolution order: session override (if enabled) -> EQEMU_EXPANSION ->
     * the server's own rule value.
     */
    public static function currentExpansion(): int
    {
        if (config('everquest.allow_era_switch') && session()->has(self::SESSION_KEY)) {
            return self::normalize(session(self::SESSION_KEY));
        }

        return self::normalize(config('everquest.expansion', 'auto'));
    }

    public static function isAll(): bool
    {
        return self::currentExpansion() === self::ALL;
    }

    /** The era the server itself is running, straight from rule_values. */
    public static function serverExpansion(): int
    {
        return Cache::remember('eqemu.rule.current_expansion', self::CACHE_TTL, function () {
            $value = DB::connection('eqemu')->table('rule_values')
                ->where('rule_name', 'Expansion:CurrentExpansion')
                ->orderByRaw('ruleset_id = 1 DESC') // ruleset 1 (`default`) is the active set
                ->value('rule_value');

            // EQEmu treats -1 as "all expansions".
            if ($value === null || (int) $value < 0) {
                return self::ALL;
            }

            return (int) $value;
        });
    }

    /** Flag names currently switched on in the content_flags table. */
    public static function enabledFlags(): array
    {
        return Cache::remember('eqemu.content_flags.enabled', self::CACHE_TTL, fn () => DB::connection('eqemu')
            ->table('content_flags')->where('enabled', 1)->pluck('flag_name')->all());
    }

    /** Flag names currently switched off. */
    public static function disabledFlags(): array
    {
        return Cache::remember('eqemu.content_flags.disabled', self::CACHE_TTL, fn () => DB::connection('eqemu')
            ->table('content_flags')->where('enabled', 0)->pluck('flag_name')->all());
    }

    /**
     * Apply the full server criteria to a query.
     *
     * @param  string  $table  table (or alias) the columns live on; '' for unqualified
     */
    public static function apply(EloquentBuilder|QueryBuilder|Relation $query, string $table = ''): EloquentBuilder|QueryBuilder|Relation
    {
        $prefix = $table === '' ? '' : $table . '.';
        $expansion = self::currentExpansion();

        if ($expansion !== self::ALL) {
            $query->where(function ($q) use ($prefix, $expansion) {
                $q->where($prefix . 'min_expansion', '<=', $expansion)
                    ->orWhere($prefix . 'min_expansion', -1);
            })->where(function ($q) use ($prefix, $expansion) {
                $q->where($prefix . 'max_expansion', '>=', $expansion)
                    ->orWhere($prefix . 'max_expansion', -1);
            });
        }

        self::applyFlags($query, $table);

        return $query;
    }

    /**
     * The content-flag half of the criteria, on its own.
     *
     * Flags gate seasonal and opt-in content (frostfell, the classic old-world
     * drop set, the parked copies of a revamped zone's spawns) independently of
     * the expansion number, so they apply even in "all eras" mode -- and to
     * anything that asks what the server holds at all rather than what is open
     * this era, which is why this is public.
     *
     * A flag name that is not in the content_flags table matches neither list,
     * so the row is hidden. That is the server's behaviour too: the criteria are
     * built from the table, and a name it has never heard of is never enabled.
     *
     * @param  string  $table  table (or alias) the columns live on; '' for unqualified
     */
    public static function applyFlags(EloquentBuilder|QueryBuilder|Relation $query, string $table = ''): EloquentBuilder|QueryBuilder|Relation
    {
        $prefix = $table === '' ? '' : $table . '.';
        $enabled = self::enabledFlags();
        $disabled = self::disabledFlags();

        $query->where(function ($q) use ($prefix, $enabled) {
            $q->whereNull($prefix . 'content_flags')
                ->orWhere($prefix . 'content_flags', '');

            if ($enabled) {
                // Column is a comma-separated list, so match it the way the server does.
                $q->orWhere(function ($inner) use ($prefix, $enabled) {
                    $inner->whereRaw(
                        "CONCAT(',', {$prefix}content_flags, ',') REGEXP ?",
                        [',(' . implode('|', array_map('preg_quote', $enabled)) . '),']
                    );
                });
            }
        });

        $query->where(function ($q) use ($prefix, $disabled) {
            $q->whereNull($prefix . 'content_flags_disabled')
                ->orWhere($prefix . 'content_flags_disabled', '');

            if ($disabled) {
                $q->orWhere(function ($inner) use ($prefix, $disabled) {
                    $inner->whereRaw(
                        "CONCAT(',', {$prefix}content_flags_disabled, ',') REGEXP ?",
                        [',(' . implode('|', array_map('preg_quote', $disabled)) . '),']
                    );
                });
            }
        });

        return $query;
    }

    /**
     * Era gate for the zone table, which needs two checks rather than one.
     *
     * The standard criteria above pick which *row* is live -- zones are
     * versioned, and PEQ marks each version's era window with
     * min/max_expansion and content flags (the Splitpaw and Cazic Thule
     * revamps, for instance, are separate rows gated that way).
     *
     * On top of that the server gates entry on the zone's own `expansion`
     * column, and `bypass_expansion_check` opts a zone out of that gate so it
     * can be opened ahead of its era. Plane of Knowledge, the guild lobby and
     * the revamped old-world zones all rely on it. From zoning.cpp:
     *
     *   if (z->expansion <= GetCurrentExpansion() || z->bypass_expansion_check)
     *
     * @param  string  $table  table (or alias) the columns live on; '' for unqualified
     */
    public static function applyZone(EloquentBuilder|QueryBuilder|Relation $query, string $table = ''): EloquentBuilder|QueryBuilder|Relation
    {
        $prefix = $table === '' ? '' : $table . '.';
        $expansion = self::currentExpansion();

        self::apply($query, $table);

        if ($expansion !== self::ALL) {
            $query->where(function ($q) use ($prefix, $expansion) {
                $q->where($prefix . 'expansion', '<=', $expansion)
                    ->orWhere($prefix . 'bypass_expansion_check', 1);
            });
        }

        return $query;
    }

    /**
     * The PHP-side twin of applyZone(), for zone rows that are already loaded.
     *
     * A null zone passes: callers use this on optional relations where "no zone
     * row" means global content (zoneid 0 forage, say), not hidden content.
     * Columns the caller did not select read as null and fall back to the
     * permissive default, so select bypass_expansion_check, min_expansion,
     * max_expansion and both content_flags columns wherever this is used.
     */
    public static function zoneInEra(?object $zone): bool
    {
        if ($zone === null) {
            return true;
        }

        if (!self::flagsAllow($zone->content_flags ?? null, self::enabledFlags())
            || !self::flagsAllow($zone->content_flags_disabled ?? null, self::disabledFlags())) {
            return false;
        }

        $current = self::currentExpansion();

        if ($current === self::ALL) {
            return true;
        }

        $min = (int) ($zone->min_expansion ?? -1);
        $max = (int) ($zone->max_expansion ?? -1);

        if (($min !== -1 && $min > $current) || ($max !== -1 && $max < $current)) {
            return false;
        }

        return (int) ($zone->expansion ?? 0) <= $current
            || (int) ($zone->bypass_expansion_check ?? 0) === 1;
    }

    /**
     * PHP equivalent of the content-flag half of applyFlags(): an unset column
     * always passes, otherwise one of the comma-separated names has to be in
     * the given list.
     */
    private static function flagsAllow(?string $flags, array $active): bool
    {
        if ($flags === null || $flags === '') {
            return true;
        }

        foreach (explode(',', $flags) as $flag) {
            if (in_array(trim($flag), $active, true)) {
                return true;
            }
        }

        return false;
    }

    /** Every era that actually has zones in this database, for the switcher. */
    public static function availableExpansions(): array
    {
        return Cache::remember('eqemu.expansions.available', 3600, fn () => DB::connection('eqemu')
            ->table('zone')->distinct()->orderBy('expansion')->pluck('expansion')->all());
    }

    public static function label(int $expansion): string
    {
        if ($expansion === self::ALL) {
            return 'All eras';
        }

        return config('everquest.expansions')[$expansion] ?? 'Expansion ' . $expansion;
    }

    /** Turn 'auto' | 'all' | '<n>' into a concrete expansion number. */
    private static function normalize(mixed $value): int
    {
        if ($value === null || $value === '' || $value === 'auto') {
            return self::serverExpansion();
        }

        if ($value === 'all') {
            return self::ALL;
        }

        return (int) $value;
    }
}
