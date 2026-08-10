@extends('layouts.default')
@section('title', 'Items')

@section('content')
    <div x-data x-init="$store.itemSearch.init()" class="relative">
        <div class="flex justify-end mb-2">
            <button @click="$store.itemSearch.toggle()"
                class="flex items-center btn btn-sm btn-soft transition" title="Toggle Item Search">
                <span x-text="$store.itemSearch.open ? 'Hide Search' : 'Show Search'"></span>
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-5 w-5 transition-transform duration-200"
                    :class="{ 'rotate-180': $store.itemSearch.open }"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>
        </div>
        <div x-show="$store.itemSearch.open" x-cloak x-transition class="mt-4">
            @include('items.partials.index.search')
        </div>
    </div>
    {{-- The search panel remembers being closed, so a loaded list has to say so
         out here -- otherwise a search scoped to a few hundred items looks like
         a search over all 118,000 that is simply missing things. --}}
    @if ($activeList)
        <div class="flex flex-wrap items-center justify-center gap-3 mt-4">
            <span class="badge badge-info">{{ $activeList->name }}</span>
            <span class="text-sm text-gray-500">
                list loaded &mdash; {{ $activeList->item_count }} items, nothing outside it can be returned
            </span>
            <a href="{{ route('items.index', collect(request()->query())->except(['list', 'page'])->all()) }}"
                class="btn btn-xs btn-soft btn-error">Clear list</a>
        </div>
    @endif

    @if ($items->isNotEmpty())
        <div class="flex w-full flex-col">
            <div class="divider uppercase text-xl font-bold text-sky-400">Items ({{ $items->total() }} Found)</div>
        </div>
        @include('items.partials.index.items-table')
    @else
        <div class="flex flex-col items-center justify-center h-64">
            <div class="text-center text-gray-500">
                <p class="text-lg font-semibold">No items found.</p>
                <p class="mt-2">
                    @if ($activeList)
                        Nothing in {{ $activeList->name }} matches the rest of the search.
                    @else
                        Try adjusting the search criteria.
                    @endif
                </p>
            </div>
        </div>
    @endif
@endsection
