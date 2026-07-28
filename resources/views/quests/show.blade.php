@extends('layouts.default')
@section('title', 'Quest - ' . $quest->display_name)

@section('content')
    @include('quests.partials.search')

    <div class="flex w-full flex-col">
        <div class="divider uppercase text-xl font-bold text-sky-400">Quest</div>
    </div>

    <div class="card card-md md:card-lg bg-neutral text-neutral-content shadow-sm mb-4">
        <div class="card-body">
            <div class="flex justify-between items-center gap-2 flex-wrap">
                <h2 class="card-title m-0">
                    {{ $quest->display_name }}
                    <div class="font-mono text-sm text-base-content/50 block">{{ $quest->relative_path }}</div>
                </h2>
                <div class="flex items-center gap-2">
                    <span class="badge badge-sm badge-outline uppercase">{{ $quest->language }}</span>
                    @if ($quest->zone === 'global')
                        <span class="badge badge-sm badge-soft badge-info">Global</span>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                @if ($npc)
                    <span>
                        Quest NPC:
                        <a href="{{ route('npcs.show', $npc->id) }}" class="link-info link-hover">
                            {{ $npc->clean_name }}
                        </a>
                        @if ($npc->level)
                            <span class="text-base-content/50">(Level {{ $npc->level }})</span>
                        @endif
                        @if ($quest->npc_ambiguous)
                            <span class="badge badge-xs badge-soft badge-warning ml-1"
                                title="More than one NPC shares this name; this is the most likely match.">
                                best match
                            </span>
                        @endif
                    </span>
                @endif
                @if ($zone)
                    <span>
                        Zone:
                        <a href="{{ route('zones.show', $zone->id) }}" class="link-info link-hover">
                            {{ $zone->long_name }}
                        </a>
                    </span>
                @endif
            </div>

            @if ($handins->isNotEmpty() || $rewards->isNotEmpty() || $mentions->isNotEmpty())
                <div class="flex flex-col gap-2 mt-2">
                    @foreach ([['Hands in', $handins], ['Rewards', $rewards], ['Mentions', $mentions]] as [$label, $set])
                        @if ($set->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs uppercase text-base-content/50 w-20 shrink-0">{{ $label }}</span>
                                @foreach ($set as $ref)
                                    @if ($ref->item)
                                        <x-item-link :item_id="$ref->item->id" :item_name="$ref->item->Name" :item_icon="$ref->item->icon" />
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            {{-- Tasks this script offers or advances; the task rows live in peq. --}}
            @if ($quest->tasks->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2 mt-2">
                    <span class="text-xs uppercase text-base-content/50 w-20 shrink-0">Tasks</span>
                    @foreach ($quest->tasks as $ref)
                        @if ($ref->task)
                            <a href="{{ route('tasks.show', $ref->task->id) }}" class="link-info link-hover text-sm"
                                title="{{ ucfirst($ref->kind) }}">
                                {{ $ref->task->title }}
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif

            @if ($quest->npcs->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2 mt-2">
                    <span class="text-xs uppercase text-base-content/50 w-20 shrink-0">Spawns</span>
                    @foreach ($quest->npcs as $ref)
                        @if ($ref->npc)
                            <a href="{{ route('npcs.show', $ref->npc->id) }}" class="link-info link-hover text-sm">
                                {{ $ref->npc->clean_name }}
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif

            @if ($siblings->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2 mt-2">
                    <span class="text-xs uppercase text-base-content/50 w-20 shrink-0">See also</span>
                    @foreach ($siblings as $sibling)
                        <a href="{{ route('quests.show', $sibling->id) }}" class="link-info link-hover text-sm"
                            title="{{ $sibling->relative_path }}">
                            {{ $sibling->display_name }} ({{ $sibling->zone }})
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="flex w-full flex-col">
        <div class="divider uppercase text-sm text-base-content/70">Script</div>
    </div>

    <pre class="text-xs overflow-x-auto bg-base-300 p-3 rounded max-h-[48rem] overflow-y-auto"><code>{{ $quest->body() ?? 'Script file is not readable from the container. Check the quests bind mount.' }}</code></pre>
@endsection
