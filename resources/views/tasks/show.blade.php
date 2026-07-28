@extends('layouts.default')
@section('title')
    Task - {{ $task->title }}
    <x-entity-id :id="$task->id" label="Task ID" />
@endsection

@section('content')
    @include('tasks.partials.search')

    <div class="flex w-full flex-col">
        <div class="divider uppercase text-xl font-bold text-sky-400">Task</div>
    </div>

    <div class="card card-md md:card-lg bg-neutral text-neutral-content shadow-sm mb-4">
        <div class="card-body">
            <div class="flex justify-between items-center">
                <h2 class="card-title m-0">
                    {{ $task->title }}
                    <div class="text-sm text-base-content/50 block">
                        @if ($task->duration === 0)
                            Infinite
                        @else
                            {{ seconds_to_human($task->duration) }}
                        @endif
                    </div>
                </h2>
                <span class="badge badge-sm badge-soft badge-info">
                    {{ config('everquest.task_types.' . $task->type) ?? 'Unknown' }}<br />
                </span>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge badge-sm badge-soft {{ $task->repeatable === 1 ? 'badge-success' : 'badge-error' }}">
                    Repeatable
                </span>
                <span class="badge badge-sm badge-soft badge-accent">
                    Min/Max Players {{ $task->min_players > 0 ? $task->min_players : 1 }} /
                    {{ $task->max_players > 0 ? $task->max_players : 'Any' }}
                </span>
            </div>
            {!! $task->final_description['global'] ?? '' !!}
            <div class="flex flex-wrap items-center gap-1">
                <h3>Rewards:</h3>
                @if (config('everquest.tasks.display.activities'))
                    @if ($task->reward_id_list)
                        @foreach ($task->rewards as $item)
                            @if ($item)
                            <x-item-link :item_id="$item->id" :item_name="$item->Name" :item_icon="$item->icon" />,
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
                            />
                            @endif
                            <span class="text-accent">x{{ number_format($task->reward_points) }}</span>
                        </div>
                    @endif

                    @if ($task->reward_method === 2 && $task->reward_text)
                        <div class="text-sm">{{ $task->reward_text }}</div>
                    @endif
                @else
                    <span class="text-base-content/50">Hidden</span>
                @endif
            </div>
        </div>
    </div>

    @if (config('everquest.tasks.display.activities'))
        <div class="grid grid-cols-1 gap-4">
            @foreach ($task->taskActivities as $activity)
                <div class="card card-md md:card-lg {{ $loop->odd ? 'bg-base-100' : 'bg-base-300' }}">
                    <div class="card-body">
                        <h2 class="card-title">
                            <span class="font-semibold text-accent">Step {{ $activity->activityid + 1 }}.</span>
                            @if ($activity->description_override !== '')
                                {{ $activity->description_override }}
                            @elseif ($activity->activitytype)
                                {{ config('everquest.task_activity_types.' . $activity->activitytype) ?? null }}
                                {{ $activity->target_name }}
                            @endif
                        </h2>
                        @if ($task->final_description)
                        <div class="italic text-base-content/50">
                            {{ collect($task->final_description['activities'])->first(fn($entry) => in_array($activity->activityid + 1, $entry['steps'] ?? []))['text'] ?? '' }}
                        </div>
                        @endif
                        @switch($activity->activitytype)
                            @case(1)
                                @include('tasks.partials.show.types.deliver')
                            @break

                            @case(2)
                                @include('tasks.partials.show.types.kill')
                            @break

                            @case(3)
                                @include('tasks.partials.show.types.loot')
                            @break

                            @case(4)
                                @include('tasks.partials.show.types.speakwith')
                            @break

                            @case(5)
                                @include('tasks.partials.show.types.explore')
                            @break

                            @case(6)
                                @include('tasks.partials.show.types.tradeskill')
                            @break

                            @case(7)
                                @include('tasks.partials.show.types.fish')
                            @break

                            @case(8)
                                @include('tasks.partials.show.types.forage')
                            @break

                            @case(9)
                            @case(10)
                                @include('tasks.partials.show.types.use')
                            @break

                            @case(11)
                                @include('tasks.partials.show.types.touch')
                            @break

                            @case(100)
                                @include('tasks.partials.show.types.givecash')
                            @break

                            @default
                        @endswitch
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="card card-md md:card-lg bg-base-300 text-base-content/50 shadow-sm mb-4">
            <div class="card-body">
                <p class="italic">Task activities are hidden.</p>
            </div>
        </div>
    @endif

    @if ($questScripts->isNotEmpty())
        @include('tasks.partials.show.scripts', ['questScripts' => $questScripts])
    @endif
@endsection
