@extends('layouts.default')

@if($item->canDisplay())
    @section('title')
        <img src="{{ asset('img/icons/' . $item->icon . '.png') }}" alt="{{ $item->Name }}" class="inline-block w-7 h-7 mr-2">
        {{ $item->Name ?? 'Unknown Item' }}
    @endsection

    @section('content')
    <div class="flex flex-col lg:flex-row lg:items-start gap-4 min-h-screen">
        <div class="sm:basis-1/3 md:basis-1/2 xl:basis-1/3 w-full lg:min-h-screen">
            <div class="sticky top-[100px]">
                @include('items.partials.show.item', ['item' => $item])
            </div>
        </div>

        <div class="sm:basis-2/3 md:basis-1/2 xl:basis-2/3 w-full">
            @if ($item->evolvinglevel > 0 && $item->evoid != 0 && $item->evolvingDetails->isNotEmpty())
            <div class="mb-4">
                <h2 class="text-xl font-bold mb-3">Evolving Levels</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                    @foreach ($item->evolvingDetails as $detail)
                        <div class="p-4 rounded-lg border border-base-300 bg-base-100 shadow-sm">
                            <div class="text-lg font-semibold">
                                Level {{ $detail->item_evolve_level }}
                            </div>
                            <div class="flex items-center gap-3 mt-3">
                                <x-item-link
                                    :item_id="$detail->item->id"
                                    :item_name="$detail->item->Name"
                                    :item_icon="$detail->item->icon"
                                    item_class="flex"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            @include('items.partials.show.drops')

            @if ($recipes->isNotEmpty())
                @include('items.partials.show.recipes', ['recipes' => $recipes])
            @endif

            @if ($used_in_ts->isNotEmpty())
                @include('items.partials.show.used_in_tradeskills', ['used_in_ts' => $used_in_ts])
            @endif

            @if ($forage->isNotEmpty())
                @include('items.partials.show.forage', ['forage' => $forage])
            @endif

            @if ($fishing->isNotEmpty())
                @include('items.partials.show.fishing', ['fishing' => $fishing])
            @endif

            @if ($ground_spawn->isNotEmpty())
                @include('items.partials.show.ground_spawn', ['ground_spawn' => $ground_spawn])
            @endif

            @if ($soldByZone->isNotEmpty())
                @include('items.partials.show.sold', ['sold' => $soldByZone])
            @endif

            @if ($questScripts->isNotEmpty())
                @include('items.partials.show.quests', ['questScripts' => $questScripts])
            @endif
        </div>
    </div>
    @endsection
@else
    @section('content')
        <div role="alert" class="alert alert-info alert-soft">
            This item has not yet been discovered.
        </div>
    @endsection
@endif
