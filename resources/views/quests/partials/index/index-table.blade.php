<div class="border border-base-content/5 overflow-x-auto">
    <table class="table table-auto md:table-fixed w-full table-zebra">
        <thead class="text-xs uppercase bg-base-300">
            <tr>
                <th scope="col" class="w-[35%]">@sortablelink('name', 'Quest NPC / Script')</th>
                <th scope="col" class="w-[25%]">@sortablelink('zone', 'Zone')</th>
                <th scope="col" class="w-[10%] hidden md:table-cell">@sortablelink('handins', 'Hand-ins')</th>
                <th scope="col" class="w-[10%] hidden md:table-cell">@sortablelink('rewards', 'Rewards')</th>
                <th scope="col" class="w-[10%] hidden lg:table-cell">@sortablelink('spawns', 'Spawns')</th>
                <th scope="col" class="w-[10%] hidden md:table-cell">@sortablelink('language', 'Type')</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quests as $quest)
                <tr>
                    <td scope="row">
                        <div class="flex flex-col">
                            <a href="{{ route('quests.show', $quest->id) }}"
                                title="{{ $quest->display_name }}"
                                class="text-base link-info link-hover">
                                {{ $quest->display_name }}
                            </a>
                            <span class="font-mono text-xs text-base-content/40">{{ $quest->relative_path }}</span>
                        </div>
                    </td>
                    <td>
                        @if ($quest->zone === 'global')
                            <span class="text-base-content/70">Global</span>
                        @else
                            {{ $zoneNames[$quest->zone] ?? $quest->zone }}
                        @endif
                    </td>
                    <td class="hidden md:table-cell">
                        @if ($quest->handin_count > 0)
                            <span class="badge badge-sm badge-soft badge-warning">{{ $quest->handin_count }}</span>
                        @endif
                    </td>
                    <td class="hidden md:table-cell">
                        @if ($quest->reward_count > 0)
                            <span class="badge badge-sm badge-soft badge-success">{{ $quest->reward_count }}</span>
                        @endif
                    </td>
                    <td class="hidden lg:table-cell">
                        @if ($quest->npcs_count > 0)
                            <span class="badge badge-sm badge-soft badge-info">{{ $quest->npcs_count }}</span>
                        @endif
                    </td>
                    <td class="hidden md:table-cell">
                        <span class="badge badge-sm badge-outline uppercase">{{ $quest->language }}</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
