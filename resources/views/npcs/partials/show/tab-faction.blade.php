<input type="radio" name="npc_details" class="tab" aria-label="Faction"
    {{ $defaultTab === 'faction' ? 'checked' : '' }} />
<div class="tab-content bg-base-100 border-base-300">
    <div class="border border-base-content/5 overflow-x-auto">
        @if ($raisesFaction)
            <table class="table table-auto md:table-fixed w-full table-zebra" data-sortable>
                <thead class="text-xs uppercase bg-base-300">
                    <tr>
                        <th scope="col" class="w-[90%]" data-sort>Faction raised by killing this npc</th>
                        <th scope="col" class="w-[10%]" data-sort="number">Value</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($raisesFaction as $faction)
                        <tr>
                            <td scope="row">
                                <a href="{{ route('factions.show', $faction['id']) }}"
                                    class="text-base link-info link-hover">
                                    {{ $faction['name'] }}
                                </a>
                            </td>
                            <td class="text-success">
                                +{{ $faction['value'] }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($lowersFaction)
            <table class="table table-auto md:table-fixed w-full table-zebra" data-sortable>
                <thead class="text-xs uppercase bg-base-300">
                    <tr>
                        <th scope="col" class="w-[90%]" data-sort>Faction lowered by killing this npc</th>
                        <th scope="col" class="w-[10%]" data-sort="number">Value</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lowersFaction as $faction)
                        <tr>
                            <td scope="row">
                                <a href="{{ route('factions.show', $faction['id']) }}"
                                    class="text-base link-info link-hover">
                                    {{ $faction['name'] }}
                                </a>
                            </td>
                            <td class="text-error">
                                {{ $faction['value'] }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
