<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kyslik\ColumnSortable\Sortable;

class DiscoveredItem extends Model
{
    use Sortable;

    public array $sortable = [
        'char_name',
        'discovered_date',
        // not a column: see itemSortable()
        'item',
    ];

    protected $connection = 'eqemu';
    protected $table = 'discovered_items';
    protected $primaryKey = 'item_id';

    /**
     * Order by the item's name, which lives one table over. Joined here rather
     * than left to the package's relation sorting, because the query already
     * carries a join and a select of its own.
     */
    public function itemSortable($query, $direction)
    {
        return $query
            ->leftJoin('items', 'items.id', '=', 'discovered_items.item_id')
            ->orderBy('items.Name', $direction);
    }

    protected $casts = [
        'item_id' => 'integer',
        'discovered_date' => 'integer',
        'account_status' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(CharacterData::class, 'char_name', 'name');
    }

    public function getDiscoveredAtAttribute()
    {
        return $this->discovered_date ? now()->createFromTimestamp($this->discovered_date) : null;
    }

    public function getMageloUrlAttribute(): ?string
    {
        if (!config('everquest.discovered_items.link_character_to_magelo')) {
            return null;
        }

        $baseUrl = config('everquest.magelo_base_url');

        if (blank($baseUrl) || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        return rtrim($baseUrl, '/') . '/character/' . urlencode(strtolower($this->char_name));
    }
}
