<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Filters\TaskFilter;
use Illuminate\Http\Request;
use App\Models\AlternateCurrency;
use App\Models\QuestScript;
use Illuminate\Support\Facades\Cache;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $tasks = Task::attachRewardsMultiple(
            (new TaskFilter($request))
                ->apply(Task::query())
                ->select([
                    'id', 'type', 'duration', 'title', 'reward_id_list', 'cash_reward', 'exp_reward',
                    'reward_points', 'reward_point_type', 'min_level', 'max_level', 'repeatable',
                ])
                ->withCount('taskActivities')
                ->where('enabled', 1)
                ->sortable(['title' => 'asc'])
                ->paginate(50)
                ->withQueryString()
        );

        // get cached alt currency since tasks could use it
        $altCurrency = AlternateCurrency::allAltCurrency();

        return view('tasks.index', [
            'tasks' => $tasks,
            'altCurrency' => $altCurrency,
            'metaTitle' => config('app.name') . ' - Task Search',
        ]);
    }

    public function show(Task $task)
    {
        $activities = Cache::remember("task_with_activities_{$task->id}", now()->addMonth(), function () use ($task) {
            $task = Task::with('taskActivities')
                ->where('id', $task->id)
                ->where('enabled', 1)
                ->firstOrFail();

            $task->final_description = $task->fixed_description;

            foreach ($task->taskActivities as $activity) {
                $activity->cached_npcs = $activity->npcs;
                $activity->cached_zones = $activity->zones;
                $activity->cached_items = $activity->items;
            }

            $task = Task::attachRewardsSingle($task);

            return $task;
        });

        // get cached alt currency since tasks could use it
        $altCurrency = AlternateCurrency::allAltCurrency();

        // The scripts that actually drive this task. Tasks are defined in peq but
        // offered and advanced from the quests/ tree, so this comes from the
        // on-disk index rather than a join, and is not part of the cache above.
        $questScripts = QuestScript::forTask($task->id)->get();

        return view('tasks.show', [
            'task' => $activities,
            'altCurrency' => $altCurrency,
            'questScripts' => $questScripts,
            'metaTitle' => config('app.name') . ' - Task: ' . $task->title,
        ]);
    }
}
