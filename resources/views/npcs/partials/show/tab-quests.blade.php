<input type="radio" name="npc_details" class="tab" aria-label="Quests ({{ $questCount }})"
    {{ $defaultTab === 'quests' ? 'checked' : '' }} />
<div class="tab-content bg-base-100 border-base-300">
    <div class="p-3 flex flex-col gap-4">
        @if ($scriptTasks->isNotEmpty() || $taskObjectives->isNotEmpty())
            <div class="border border-base-content/10 rounded">
                <div class="bg-neutral px-3 py-2 text-sm font-medium">Tasks</div>
                <div class="px-3 py-2 flex flex-col gap-2">
                    @foreach ([['Offers', 'offer'], ['Advances', 'update'], ['References', 'mentioned']] as [$label, $kind])
                        @php $set = $scriptTasks->where('kind', $kind); @endphp
                        @if ($set->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs uppercase text-base-content/50 w-20 shrink-0">{{ $label }}</span>
                                @foreach ($set as $ref)
                                    <a href="{{ route('tasks.show', $ref->task->id) }}" class="link-info link-hover text-sm"
                                        title="View task page">{{ $ref->task->title }}</a>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                    @if ($taskObjectives->isNotEmpty())
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs uppercase text-base-content/50 w-20 shrink-0">Target of</span>
                            @foreach ($taskObjectives as $obj)
                                <span class="flex items-center gap-1">
                                    <a href="{{ route('tasks.show', $obj->task->id) }}" class="link-info link-hover text-sm"
                                        title="View task page">{{ $obj->task->title }}</a>
                                    @if ($obj->types->isNotEmpty())
                                        <span class="text-xs text-base-content/50">({{ $obj->types->join(', ') }})</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
        @foreach ($questScripts as $script)
            @php
                $handins = $script->items->where('kind', 'handin');
                $rewards = $script->items->where('kind', 'reward');
                $mentions = $script->items->where('kind', 'mentioned');
            @endphp
            <div class="border border-base-content/10 rounded">
                <div class="bg-neutral px-3 py-2 flex flex-wrap items-center justify-between gap-2">
                    <a href="{{ route('quests.show', $script->id) }}" class="font-mono text-sm text-accent/80 link-hover"
                        title="View quest page">
                        {{ $script->relative_path }}
                    </a>
                    <span class="badge badge-sm badge-outline uppercase">{{ $script->language }}</span>
                </div>

                @if ($handins->isNotEmpty() || $rewards->isNotEmpty() || $mentions->isNotEmpty())
                    <div class="px-3 py-2 flex flex-col gap-2 border-b border-base-content/10">
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

                <div class="collapse collapse-arrow">
                    <input type="checkbox" />
                    <div class="collapse-title text-sm font-medium">View script</div>
                    <div class="collapse-content">
                        <pre class="text-xs overflow-x-auto bg-base-300 p-3 rounded max-h-[32rem] overflow-y-auto"><code>{{ $script->body() ?? 'Script file is not readable from the container. Check the quests bind mount.' }}</code></pre>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
