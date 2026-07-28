<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One quest script file from the server's quests/ tree.
 *
 * Note this model lives on the DEFAULT (sqlite) connection, not 'eqemu' -- the
 * index is derived data owned by this app, and peq stays read-only.
 */
class QuestScript extends Model
{
    protected $table = 'quest_scripts';

    protected $fillable = [
        'zone', 'file_name', 'relative_path', 'language',
        'npc_name', 'npc_id', 'npc_ambiguous', 'bytes', 'sha1',
    ];

    protected $casts = [
        'npc_ambiguous' => 'boolean',
    ];

    /**
     * Human-friendly name for pages and links: the NPC name with the
     * filesystem substitutions undone ('_' for spaces, '#' marker stripped).
     */
    public function getDisplayNameAttribute(): string
    {
        $base = $this->npc_name ?? preg_replace('/\.(lua|pl)$/', '', $this->file_name);

        return str_replace(['_', '#'], [' ', ''], $base);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuestScriptItem::class);
    }

    public function npcs(): HasMany
    {
        return $this->hasMany(QuestScriptNpc::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(QuestScriptTask::class);
    }

    /** Scripts attached to a given NPC, plus any that reference it. */
    public static function forNpc(int $npcId)
    {
        return self::query()
            ->where('npc_id', $npcId)
            ->orWhereHas('npcs', fn ($q) => $q->where('npc_id', $npcId))
            ->orderBy('zone')
            ->orderBy('file_name');
    }

    /** Scripts that hand in, reward, or mention a given item. */
    public static function forItem(int $itemId)
    {
        return self::query()
            ->whereHas('items', fn ($q) => $q->where('item_id', $itemId))
            ->with(['items' => fn ($q) => $q->where('item_id', $itemId)])
            ->orderBy('zone')
            ->orderBy('file_name');
    }

    /** Scripts that offer, update, or mention a given task. */
    public static function forTask(int $taskId)
    {
        return self::query()
            ->whereHas('tasks', fn ($q) => $q->where('task_id', $taskId))
            ->with(['tasks' => fn ($q) => $q->where('task_id', $taskId)])
            ->orderBy('zone')
            ->orderBy('file_name');
    }

    /**
     * Read the script body off disk on demand. Bodies are deliberately not
     * stored in the index -- 7.5k scripts is a lot of text to duplicate, and
     * the mount is right there.
     */
    public function body(): ?string
    {
        $path = rtrim(config('everquest.quests_root'), '/') . '/' . ltrim($this->relative_path, '/');

        if (!is_readable($path)) {
            return null;
        }

        return file_get_contents($path);
    }
}
