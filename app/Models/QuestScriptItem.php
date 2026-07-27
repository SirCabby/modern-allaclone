<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestScriptItem extends Model
{
    protected $table = 'quest_script_items';
    public $timestamps = false;

    protected $fillable = ['quest_script_id', 'item_id', 'kind'];

    public function questScript(): BelongsTo
    {
        return $this->belongsTo(QuestScript::class);
    }

    /** Cross-connection: the item itself lives in peq. */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id')->select('id', 'Name', 'icon');
    }
}
