<?php

namespace App\Models;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Kyslik\ColumnSortable\Sortable;

class Task extends Model
{
    use HasFactory;
    use Sortable;

    public array $sortable = [
        'title',
        // sorts on the raw column, which is the order the labels are numbered in
        'type',
        'min_level',
        'max_level',
        'repeatable',
        // not a column: the activity count withCount() hangs on the row
        'steps',
    ];

    protected $connection = 'eqemu';
    protected $table = 'tasks';

    public function stepsSortable($query, $direction)
    {
        return $query->orderBy('task_activities_count', $direction);
    }

    public function getTaskTypeAttribute(): string
    {
        return match ($this->type) {
            0 => 'Task',
            1 => 'Shared',
            2 => 'Quest',
            default => 'Unknown',
        };
    }

    public function getFixedDescriptionAttribute()
    {
        $desc = $this->description ?? '';
        if (!$desc) return '';

        $regex = '/\[(.*?)\]/';

        $result = [
            'global' => $this->replaceDescriptionContent(trim(preg_replace($regex, '', $desc))),
            'activities' => []
        ];

        if (preg_match_all($regex, $desc, $matches)) {
            foreach ($matches[1] as $match) {
                $desc_split = array_map('trim', explode(',', $match));
                $steps = [];

                // desc can be associated with multiple steps
                while (!empty($desc_split) && is_numeric($desc_split[0])) {
                    // pluck the step
                    $steps[] = (int) array_shift($desc_split);
                }

                if ($steps && $desc_split) {
                    $description = $this->replaceDescriptionContent(implode(',', $desc_split));

                    $result['activities'][] = [
                        'steps' => $steps,
                        'text' => $description,
                    ];
                }
            }
        }

        return $result;
    }

    private function replaceDescriptionContent($desc): string
    {
        $desc = trim($desc);
        $desc = str_replace(']', '', $desc);
        $desc = str_ireplace('<br>', '<br />', $desc);
        $desc = preg_replace_callback('/<c\s+"(#[0-9A-Fa-f]{3,6})">/', function ($matches) {
            return '<span style="color: ' . $matches[1] . '">';
        }, $desc);
        $desc = str_ireplace('</c>', '</span>', $desc);

        return $desc;
    }

    public function taskActivities(): HasMany
    {
        return $this->hasMany(TaskActivity::class, 'taskid', 'id')
            ->orderBy('activityid', 'asc')
            ->orderBy('step', 'asc');
    }

    /**
     * Tasks that hand out an item.
     *
     * reward_id_list is a '|' separated string, so it has to be matched as a set:
     * LIKE '%67106%' would also hit item 167106, and '0' would hit everything.
     */
    public static function rewardingItem(int $itemId)
    {
        return self::query()
            ->where('enabled', 1)
            ->whereRaw("FIND_IN_SET(?, REPLACE(reward_id_list, '|', ','))", [$itemId])
            ->orderBy('id');
    }

    /** Tasks with an activity that loots, delivers or otherwise names an item. */
    public static function requiringItem(int $itemId)
    {
        return self::query()
            ->where('enabled', 1)
            ->whereExists(
                fn ($q) => $q->from('task_activities')
                    ->whereColumn('task_activities.taskid', 'tasks.id')
                    ->whereRaw("FIND_IN_SET(?, REPLACE(task_activities.item_id_list, '|', ','))", [$itemId])
            )
            ->orderBy('id');
    }

    /**
     * Every task an item is involved in, tagged with how: 'reward' when the task
     * pays it out, 'objective' when an activity asks for it, 'both' for both.
     *
     * This is the reverse of the reward/objective lists on the task page -- there
     * is no join table to lean on, hence the two set-matched queries above.
     */
    public static function forItem(int $itemId): Collection
    {
        $rewards = self::rewardingItem($itemId)->get();
        $objectives = self::requiringItem($itemId)->get();
        $rewardIds = $rewards->pluck('id')->all();

        return $rewards
            ->each(fn ($t) => $t->item_role = $objectives->contains('id', $t->id) ? 'both' : 'reward')
            ->concat(
                $objectives
                    ->reject(fn ($t) => in_array($t->id, $rewardIds, true))
                    ->each(fn ($t) => $t->item_role = 'objective')
            )
            ->sortBy('id')
            ->values();
    }

    public static function attachRewardsMultiple(Collection|Paginator $tasks): Collection|Paginator
    {
        $arePaginated = $tasks instanceof Paginator;

        $allTasks = $arePaginated ? $tasks->getCollection() : $tasks;

        $rewardIds = $allTasks->flatMap(function ($task) {
            return explode('|', $task->reward_id_list);
        })->unique()->filter();

        $items = Item::whereIn('id', $rewardIds)->select('id', 'Name', 'icon')->get()->keyBy('id');

        $allTasks->each(function ($task) use ($items) {
            $ids = array_map('intval', explode('|', $task->reward_id_list));
            $task->rewards = collect($ids)
                ->map(fn ($id) => $items[$id] ?? null)
                ->filter();
        });

        if ($arePaginated) {
            $tasks->setCollection($allTasks);

            return $tasks;
        }

        return $allTasks;
    }

    public static function attachRewardsSingle(Task $task): Task
    {
        $rewardIds = array_map('intval', explode('|', $task->reward_id_list));
        $items = Item::whereIn('id', $rewardIds)->select('id', 'Name', 'icon')->get()->keyBy('id');

        $task->rewards = collect($rewardIds)
            ->map(fn ($id) => $items[$id] ?? null)
            ->filter();

        return $task;
    }
}
