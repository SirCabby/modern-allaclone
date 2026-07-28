{{-- The quest scripts that offer and advance this task. Tasks are defined in peq
     but driven from the server's quests/ tree, so this comes from the index built
     by `php artisan quests:index` -- the reverse of the task list on a script. --}}
<div class="w-full flex flex-col mb-7">
    <div class="divider">Quest scripts driving this task</div>
    <ul role="list" class="list bg-base-300 divide-y divide-base-200">
        @foreach ($questScripts as $script)
            @php $kind = $script->tasks->first()?->kind; @endphp
            <li class="flex justify-between items-center gap-x-6 px-3 py-2">
                <div class="min-w-0 flex-auto">
                    <p class="text-sm/6 font-semibold text-neutral-content">
                        @if ($script->npc_id)
                            <a href="{{ route('npcs.show', $script->npc_id) }}" class="link-info link-hover">
                                {{ str_replace('_', ' ', $script->npc_name ?? ('NPC ' . $script->npc_id)) }}
                            </a>
                        @else
                            <span class="text-base-content/70">{{ str_replace('_', ' ', $script->npc_name ?? $script->file_name) }}</span>
                        @endif
                        <span class="ml-2 text-xs text-sky-300">({{ $script->zone }})</span>
                    </p>
                    <p class="font-mono text-xs">
                        <a href="{{ route('quests.show', $script->id) }}" class="link-hover text-base-content/40"
                            title="View quest script">{{ $script->relative_path }}</a>
                    </p>
                </div>
                <div class="shrink-0">
                    @if ($kind === 'offer')
                        <span class="badge badge-sm badge-success">Offers</span>
                    @elseif ($kind === 'update')
                        <span class="badge badge-sm badge-warning">Advances</span>
                    @else
                        <span class="badge badge-sm badge-ghost">References</span>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</div>
