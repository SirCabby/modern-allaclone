<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One item's membership of one ItemList. No key of its own -- the pair is the
 * key -- and no timestamps; the list's own `indexed_at` dates the whole set.
 */
class ItemListItem extends Model
{
    protected $table = 'item_list_items';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['item_list_id', 'item_id'];

    protected $casts = [
        'item_list_id' => 'integer',
        'item_id' => 'integer',
    ];
}
