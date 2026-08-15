<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The era an item belongs to, as built by `php artisan items:index-eras`.
 *
 * Note this model lives on the DEFAULT (sqlite) connection, not 'eqemu' -- the
 * index is derived data owned by this app, and peq stays read-only. That also
 * means it can never be joined against `items`; callers pull ids across.
 */
class ItemExpansion extends Model
{
    public const ERAS_CACHE_KEY = 'items.eras.available';

    /** Prefix for the per-column orderings built by orderedItemIds(). */
    public const ORDER_CACHE_KEY = 'items.eras.ordered';

    /** The columns fed by this index, and so the ones it can order. */
    public const ORDERABLE = ['era', 'zone'];

    protected $table = 'item_expansions';

    protected $primaryKey = 'item_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['item_id', 'expansion', 'source', 'zone', 'indexed_at'];

    protected $casts = [
        'item_id' => 'integer',
        'expansion' => 'integer',
        'indexed_at' => 'datetime',
    ];

    /** How the era was worked out, for the tooltip on the results table. */
    private const SOURCE_LABELS = [
        'loot' => 'dropped by an NPC',
        'merchant' => 'sold by a merchant',
        'forage' => 'foraged',
        'fishing' => 'fished',
        'ground' => 'a ground spawn',
        'quest' => 'a quest script',
        'handin' => 'turned in for a quest',
        'task' => 'a task reward',
        'objective' => 'collected for a task',
        'recipe' => 'a tradeskill recipe',
        'ldon' => 'an LDoN adventure merchant',
    ];

    public static function sourceLabel(?string $source): string
    {
        return self::SOURCE_LABELS[$source] ?? (string) $source;
    }

    /**
     * Eras that actually have items indexed against them, ascending.
     *
     * The checklist is built from this rather than from the full expansion list
     * so it never offers a box that cannot match anything -- and so it renders
     * as nothing at all until the index has been built.
     */
    public static function availableEras(): array
    {
        return Cache::remember(self::ERAS_CACHE_KEY, 3600, fn () => self::query()
            ->distinct()
            ->orderBy('expansion')
            ->pluck('expansion')
            ->map(fn ($era) => (int) $era)
            ->all());
    }

    /** Every item id tied to one of the given eras. */
    public static function itemIdsForEras(array $eras): array
    {
        if (!$eras) {
            return [];
        }

        return self::query()
            ->whereIntegerInRaw('expansion', $eras)
            ->pluck('item_id')
            ->all();
    }

    /** The era for one item, or null if the index holds nothing for it. */
    public static function forItem(int $itemId): ?self
    {
        return self::query()->find($itemId);
    }

    /**
     * Every indexed item id, ascending by era or by zone name.
     *
     * The Era and Zone columns are the two the results table cannot sort in
     * SQL: this index is sqlite and `items` is peq, so there is no join to
     * order by and no id list small enough to inline as one. Handing back the
     * whole ordering instead lets the caller keep the ids its search matched
     * and page through those -- 47k integers, built once and cached until the
     * next `items:index-eras`.
     *
     * Zone order follows the name the table prints rather than the short name
     * stored here, and rows dated without a place -- crafted goods, LDoN
     * purchases -- are left out entirely: they show "-", so they belong with
     * the undated items at the bottom rather than under an empty name.
     */
    public static function orderedItemIds(string $by): array
    {
        if (!in_array($by, self::ORDERABLE, true)) {
            return [];
        }

        return Cache::remember(self::ORDER_CACHE_KEY . ".{$by}", 3600, function () use ($by) {
            if ($by === 'era') {
                return self::query()
                    ->orderBy('expansion')
                    ->orderBy('item_id')
                    ->pluck('item_id')
                    ->all();
            }

            $rows = self::query()
                ->whereNotNull('zone')
                ->where('zone', '!=', '')
                ->orderBy('item_id')
                ->get(['item_id', 'expansion', 'zone']);

            $zones = Zone::byShortNames($rows->pluck('zone')->unique()->values()->all());

            // Which version of a zone a row means depends on its era, so the
            // name is resolved once per (zone, era) pair rather than once per
            // item -- a few hundred lookups instead of forty thousand.
            $names = [];

            foreach ($rows as $row) {
                $key = $row->zone . '|' . $row->expansion;
                $names[$key] ??= Zone::forEra($zones, $row->zone, $row->expansion)?->long_name;
            }

            return $rows
                ->filter(fn ($row) => $names[$row->zone . '|' . $row->expansion] !== null)
                ->sortBy(fn ($row) => $names[$row->zone . '|' . $row->expansion], SORT_NATURAL | SORT_FLAG_CASE)
                ->pluck('item_id')
                ->all();
        });
    }

    /** Eras for the fifty-odd rows on one page of results, keyed by item id. */
    public static function forItems(array $itemIds): Collection
    {
        if (!$itemIds) {
            return collect();
        }

        return self::query()
            ->whereIntegerInRaw('item_id', $itemIds)
            ->get()
            ->keyBy('item_id');
    }
}
