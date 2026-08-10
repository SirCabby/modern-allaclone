<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Kyslik\ColumnSortable\Sortable;

/**
 * One quest script file from the server's quests/ tree.
 *
 * Note this model lives on the DEFAULT (sqlite) connection, not 'eqemu' -- the
 * index is derived data owned by this app, and peq stays read-only.
 */
class QuestScript extends Model
{
    use Sortable;

    public array $sortable = [
        // The Zone column reads long names but sorts on the short one it stores,
        // which groups the results by zone either way -- and is the order the
        // index already arrives in.
        'zone',
        'language',
        // Not columns: the displayed name is assembled from two of them, and the
        // three counts are aliases withCount() hangs on the row.
        'name',
        'handins',
        'rewards',
        'spawns',
    ];

    protected $table = 'quest_scripts';

    protected $fillable = [
        'zone', 'file_name', 'relative_path', 'language',
        'npc_name', 'npc_id', 'npc_ambiguous', 'bytes', 'sha1',
    ];

    protected $casts = [
        'npc_ambiguous' => 'boolean',
    ];

    /** Order the way display_name reads: the NPC name, or the file when it has none. */
    public function nameSortable($query, $direction)
    {
        return $query->orderByRaw("COALESCE(NULLIF(npc_name, ''), file_name) COLLATE NOCASE {$direction}");
    }

    public function handinsSortable($query, $direction)
    {
        return $query->orderBy('handin_count', $direction);
    }

    public function rewardsSortable($query, $direction)
    {
        return $query->orderBy('reward_count', $direction);
    }

    public function spawnsSortable($query, $direction)
    {
        return $query->orderBy('npcs_count', $direction);
    }

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

    /**
     * How this script treats an item, for the badge on the item page.
     *
     * One script can reference the same item several ways -- an NPC that takes
     * a breastplate and later hands it back has it as both -- and once turn-ins
     * are grouped by branch it can do so several times over. Hand-in wins,
     * because it is the more specific claim.
     *
     * Reads whatever `items` is loaded with, so scope the eager load to the
     * item you are asking about (see forItem()).
     */
    public function kindOfItem(): string
    {
        $kinds = $this->items->pluck('kind');

        foreach (['handin', 'reward'] as $kind) {
            if ($kinds->contains($kind)) {
                return $kind;
            }
        }

        return 'mentioned';
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
