@extends('layouts.default')
@section('title', 'Discovered Items')

@section('content')
    @include('discovery.partials.filters')

    @if ($items->isNotEmpty())
        <div class="flex w-full flex-col">
            <div class="divider uppercase text-xl font-bold text-sky-400">Discovered Items ({{ $items->total() }} Found)</div>
        </div>

        <div class="border border-base-content/5 overflow-x-auto">
            <table class="table table-auto md:table-fixed w-full table-zebra">
                <thead class="text-xs uppercase bg-base-300">
                    <tr>
                        <th scope="col" class="w-[20%]">@sortablelink('char_name', 'Character')</th>
                        <th scope="col">@sortablelink('item', 'Item')</th>
                        <th scope="col" class="w-[15%]">@sortablelink('discovered_date', 'Date Discovered')</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($items as $item)
                    @if (!$item->item)
                        @continue
                    @endif
                    <tr>
                        <td>
                            @if ($item->magelo_url)
                                <a href="{{ $item->magelo_url }}" target="_blank" rel="noopener noreferrer"
                                    class="text-base link-accent link-hover">
                                    {{ $item->char_name }}
                                </a>
                            @else
                                {{ $item->char_name }}
                            @endif
                        </td>
                        <td>
                            @if ($item->item)
                            <x-item-link
                                :item_id="$item->item->id"
                                :item_name="$item->item->Name"
                                :item_icon="$item->item->icon"
                                item_class="flex"
                            />
                            @endif
                        </td>
                        <td>
                            <span class="text-xs text-gray-400">
                                {{ $item->discovered_at?->format('M d, Y H:i') }}
                            </span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $items->onEachSide(2)->links() }}
    @endif
@endsection
