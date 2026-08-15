<div class="border border-base-content/5 overflow-x-auto">
    <table class="table table-auto md:table-fixed w-full table-zebra">
        <thead class="text-xs uppercase bg-base-300">
            <tr>
                <th scope="col" class="w-[30%]">@sortablelink('title', 'Tasks')</th>
                <th scope="col" class="w-[10%] hidden md:table-cell">@sortablelink('steps', 'Steps')</th>
                <th scope="col" class="w-[10%] hidden md:table-cell">@sortablelink('type', 'Type')</th>
                <th scope="col" class="w-[10%] hidden lg:table-cell truncate">@sortablelink('min_level', 'Min Lvl')</th>
                <th scope="col" class="w-[10%] hidden lg:table-cell truncate">@sortablelink('max_level', 'Max Lvl')</th>
                <th scope="col" class="w-[10%] hidden md:table-cell">@sortablelink('repeatable', 'Repeat')</th>
                <th scope="col" class="w-[20%]">@sortablelink('rewards', 'Rewards')</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tasks as $task)
                <tr>
                    <td scope="row">
                        <div class="flex flex-col">
                            <a href="{{ route('tasks.show', $task->id) }}"
                                title="{{ $task->title }}"
                                class="text-base link-info link-hover">
                                {{ $task->title }}
                            </a>
                            <span class="text-xs uppercase text-gray-500">
                                Duration:
                                <span class="text-accent">
                                    {{ $task->duration > 0 ? seconds_to_human($task->duration) : 'None' }}
                                </span>
                            </span>
                        </div>
                    </td>
                    <td>
                        @if (config('everquest.tasks.display.activities'))
                            {{ $task->task_activities_count }}
                        @else
                            <span class="text-base-content/50">Hidden</span>
                        @endif
                    </td>
                    <td class="hidden md:table-cell">{{ $task->task_type }}</td>
                    <td class="hidden lg:table-cell">{{ $task->min_level }}</td>
                    <td class="hidden lg:table-cell">{{ $task->max_level }}</td>
                    <td class="hidden md:table-cell">
                        {!! $task->repeatable
                            ? '<span class="badge badge-soft badge-accent">Yes</span>'
                            : '<span class="badge badge-soft badge-warning">No</span>' !!}
                    </td>
                    <td class="task-rewards text-base-content/50">
                        @if (config('everquest.tasks.display.rewards'))
                            @if ($task->reward_id_list)
                                @foreach ($task->rewards as $item)
                                    @if ($item)
                                    <x-item-link
                                        :item_id="$item->id"
                                        :item_name="$item->Name"
                                        :item_icon="$item->icon"
                                        item_class="flex"
                                    />
                                    @endif
                                @endforeach
                            @endif

                            @if ($task->cash_reward > 0)
                                <div class="text-sm text-success">Coin: {{ price($task->cash_reward) }}</div>
                            @endif

                            @if ($task->exp_reward > 0)
                                <div class="text-sm text-secondary">Exp: {{ number_format($task->exp_reward) }}</div>
                            @endif

                            @php
                                $currency = $altCurrency->firstWhere('id', $task->reward_point_type);
                            @endphp
                            @if ($task->reward_points > 0 && $task->reward_point_type)
                                <div class="text-sm flex items-center gap-1">
                                    @if ($currency && $currency->item)
                                    <x-item-link
                                        :item_id="$currency->item->id"
                                        :item_name="$currency->item->Name"
                                        :item_icon="$currency->item->icon"
                                        item_class="flex"
                                    />
                                    @endif
                                    <span class="text-accent">x{{ number_format($task->reward_points) }}</span>
                                </div>
                            @endif
                        @else
                            <span class="text-base-content/50">Hidden</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
