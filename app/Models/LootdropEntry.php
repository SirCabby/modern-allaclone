<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LootdropEntry extends Model
{
    use HasFactory;

    protected $connection = 'eqemu';
    protected $table = 'lootdrop_entries';

    public function lootdrop(): BelongsTo
    {
        return $this->belongsTo(LootDrop::class, 'lootdrop_id', 'id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id')
            ->select('id', 'Name', 'icon');
    }
}
