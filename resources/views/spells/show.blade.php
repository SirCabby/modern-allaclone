@extends('layouts.default')

@section('title')
    <img src="{{ asset('img/icons/' . $spell->new_icon . '.png') }}" alt="{{ $spell->name }}"
        class="inline-block w-7 h-7 mr-2">
    {{ $spell->name }}
    <x-entity-id :id="$spell->id" label="Spell ID" />
@endsection

@section('content')

    @php
        $minlvl = 70;
        $spellClasses = [];
        for ($i = 1; $i <= 16; $i++) {
            $cls = $spell->{'classes' . $i};

            if ($cls > 0 && $cls < 255) {
                $spellClasses[] = config('everquest.classes.' . $i) . ' (' . $cls . ')';

                if ($cls < $minlvl) {
                    $minlvl = $cls;
                }
            }
        }

        $clsOutput = implode(', ', $spellClasses);

        $targetType = config('everquest.spell_targets.' . $spell->targettype) ?? null;

        $duration = getBuffDuration($spell);
        $duration = $duration == 0 ? 'Instant' : seconds_to_human($duration * 6);
    @endphp
    <div class="flex flex-col lg:flex-row lg:items-start gap-4">
        <div class="sm:basis-1/3 md:basis-1/2 lg:basis-2/3 xl:basis-2/3 w-full">
            @if ($description)
                <div class="mb-6 p-4 bg-base-300 rounded">
                    <p>{{ $description }}</p>
                </div>
            @else
                <div class="mb-6 p-4 bg-base-300 rounded">
                    <p>No description found</p>
                </div>
            @endif

            <div class="gap-4 text-sm">
                @include('spells.partials.show.details')
            </div>
        </div>

        <div class="sm:basis-2/3 md:basis-1/2 lg:basis-1/3 xl:basis-1/3 w-full space-y-4">
            <div class="card bg-base-300 text-base-content shadow-sm">
                <div class="card-body">
                    <h2 class="card-title">Information</h2>
                    <div class="space-y-2 divide-y divide-base-content/5">
                        <div>
                            <div class="text-base font-extrabold">Classes</div>
                            {{ $clsOutput }}
                        </div>
                        @if ($spell->scrolleffect->isNotEmpty())
                            <div>
                                <div class="text-base font-extrabold">Learned from</div>
                                @foreach ($spell->scrolleffect as $item)
                                    @if(isUndiscoveredItem($item->id, $discoveredItems))
                                        <span class="text-warning">Undiscovered Item</span>
                                    @else
                                        <x-item-link
                                            :item_id="$item->id"
                                            :item_name="$item->Name"
                                            :item_icon="$item->icon"
                                            item_class="flex"
                                        />
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h2 class="card-title text-info/70"">Items with this effect</h2>
                    <div class="space-y-2">
                        @if (
                                $spell->clickeffect->isNotEmpty() ||
                                $spell->proceffect->isNotEmpty() ||
                                $spell->focuseffect->isNotEmpty() ||
                                $spell->worneffect->isNotEmpty()
                            )
                            @foreach ($spell->clickeffect as $click)
                                <span class="inline-flex w-full items-center gap-1 whitespace-nowrap">
                                    @if(isUndiscoveredItem($click->id, $discoveredItems))
                                        <span class="text-warning">Undiscovered Item</span>
                                    @else
                                        <x-item-link
                                            :item_id="$click->id"
                                            :item_name="$click->Name"
                                            :item_icon="$click->icon"
                                            item_class="flex"
                                        />
                                    @endif
                                    <span class="font-sm text-accent">(Click)</span>
                                </span>
                            @endforeach
                            @foreach ($spell->proceffect as $proc)
                                <span class="inline-flex w-full items-center gap-1 whitespace-nowrap">
                                    @if(isUndiscoveredItem($proc->id, $discoveredItems))
                                        <span class="text-warning">Undiscovered Item</span>
                                    @else
                                        <x-item-link
                                            :item_id="$proc->id"
                                            :item_name="$proc->Name"
                                            :item_icon="$proc->icon"
                                            item_class="flex"
                                        />
                                    @endif
                                    <span class="font-sm text-accent">(Proc)</span>
                                </span>
                            @endforeach
                            @foreach ($spell->focuseffect as $focus)
                                <span class="inline-flex items-center gap-1 whitespace-nowrap">
                                    @if(isUndiscoveredItem($focus->id, $discoveredItems))
                                        <span class="text-warning">Undiscovered Item</span>
                                    @else
                                        <x-item-link
                                            :item_id="$focus->id"
                                            :item_name="$focus->Name"
                                            :item_icon="$focus->icon"
                                            item_class="flex"
                                        />
                                    @endif
                                    <span class="font-sm text-accent">(Focus)</span>
                                </span>
                            @endforeach
                            @foreach ($spell->worneffect as $worn)
                                <span class="inline-flex items-center gap-1 whitespace-nowrap">
                                    @if(isUndiscoveredItem($worn->id, $discoveredItems))
                                        <span class="text-warning">Undiscovered Item</span>
                                    @else
                                        <x-item-link
                                            :item_id="$worn->id"
                                            :item_name="$worn->Name"
                                            :item_icon="$worn->icon"
                                            item_class="flex"
                                        />
                                    @endif
                                    <span class="font-sm text-accent">(Worn)</span>
                                </span>
                            @endforeach
                        @else
                            None
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
