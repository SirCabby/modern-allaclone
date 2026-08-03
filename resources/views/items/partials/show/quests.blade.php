{{-- Quest scripts that hand in, reward, or name this item. Sourced from the
     server's quests/ tree via `php artisan quests:index`, not from peq. --}}
<div class="w-full flex flex-col mb-7">
    <div class="divider">This item appears in quest scripts</div>
    <ul role="list" class="list bg-base-300 divide-y divide-base-200">
        @foreach ($questScripts as $script)
            @php $kind = $script->kindOfItem(); @endphp
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
                    @if ($kind === 'handin')
                        <span class="badge badge-sm badge-warning">Hand-in</span>
                    @elseif ($kind === 'reward')
                        <span class="badge badge-sm badge-success">Reward</span>
                    @else
                        <span class="badge badge-sm badge-ghost">Mentioned</span>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</div>
