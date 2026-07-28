{{-- Tasks that pay this item out or ask for it. The reverse of the reward and
     objective lists on the task page; matched against tasks.reward_id_list and
     task_activities.item_id_list, both of which are '|' separated id strings. --}}
<div class="w-full flex flex-col mb-7">
    <div class="divider">This item is part of a task</div>
    <ul role="list" class="list bg-base-300 divide-y divide-base-200">
        @foreach ($tasks as $task)
            <li class="flex justify-between items-center gap-x-6 px-3 py-2">
                <div class="min-w-0 flex-auto">
                    <p class="text-sm/6 font-semibold text-neutral-content">
                        <a href="{{ route('tasks.show', $task->id) }}" class="link-info link-hover">
                            {{ $task->title }}
                        </a>
                        <span class="ml-2 text-xs text-sky-300">({{ $task->task_type }})</span>
                    </p>
                    @if ($task->reward_text)
                        <p class="text-xs text-base-content/40">{{ $task->reward_text }}</p>
                    @endif
                </div>
                <div class="shrink-0">
                    @if ($task->item_role === 'both')
                        <span class="badge badge-sm badge-warning">Objective</span>
                        <span class="badge badge-sm badge-success">Reward</span>
                    @elseif ($task->item_role === 'objective')
                        <span class="badge badge-sm badge-warning">Objective</span>
                    @else
                        <span class="badge badge-sm badge-success">Reward</span>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</div>
