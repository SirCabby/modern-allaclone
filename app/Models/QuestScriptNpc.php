<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestScriptNpc extends Model
{
    protected $table = 'quest_script_npcs';
    public $timestamps = false;

    protected $fillable = ['quest_script_id', 'npc_id'];

    public function questScript(): BelongsTo
    {
        return $this->belongsTo(QuestScript::class);
    }

    /** Cross-connection: the NPC itself lives in peq. */
    public function npc(): BelongsTo
    {
        return $this->belongsTo(NpcType::class, 'npc_id', 'id')->select('id', 'name', 'level');
    }
}
