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
