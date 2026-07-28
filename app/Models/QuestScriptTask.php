<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestScriptTask extends Model
{
    protected $table = 'quest_script_tasks';
    public $timestamps = false;

    protected $fillable = ['quest_script_id', 'task_id', 'kind'];

    public function questScript(): BelongsTo
    {
        return $this->belongsTo(QuestScript::class);
    }

    /** Cross-connection: the task itself lives in peq. */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id', 'id')->select('id', 'title', 'type');
    }
}
