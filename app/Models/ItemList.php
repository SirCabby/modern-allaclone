<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A named set of items the search can be pinned to, as built by
 * `php artisan items:index-lists` from the text files in resources/item-lists.
 *
 * Like ItemExpansion this lives on the DEFAULT (sqlite) connection rather than
 * 'eqemu' -- it is this app's own data, so it cannot be joined against `items`
 * and callers pull the ids across instead.
 */
class ItemList extends Model
{
    protected $table = 'item_lists';

    public $timestamps = false;

    protected $fillable = ['slug', 'name', 'item_count', 'indexed_at'];

    protected $casts = [
        'item_count' => 'integer',
        'indexed_at' => 'datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(ItemListItem::class);
    }

    /**
     * Every list with something in it, for the picker.
     *
     * An empty list is left out rather than offered: picking it would return
     * nothing at all, which reads as a broken search rather than an empty list.
     * With no lists indexed the picker renders as nothing, the same way the era
     * checklist does before its index exists.
     */
    public static function available(): Collection
    {
        return self::query()
            ->where('item_count', '>', 0)
            ->orderBy('name')
            ->get();
    }

    public static function findBySlug(?string $slug): ?self
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return self::query()->where('slug', $slug)->first();
    }

    /** The item ids in this list. */
    public function itemIds(): array
    {
        return $this->entries()->pluck('item_id')->all();
    }

    /** The item ids in a list named by slug, or null if there is no such list. */
    public static function itemIdsForSlug(?string $slug): ?array
    {
        return self::findBySlug($slug)?->itemIds();
    }
}
