@extends('layouts.default')
@section('title', 'AAs')

@section('content')
    <div x-data>

        @include('aa.partials.filters')

        @if ($abilities->isNotEmpty())
            <div class="flex w-full flex-col">
                <div class="divider uppercase text-xl font-bold text-sky-400">AAs ({{ $abilities->total() }} Found)</div>
            </div>

            <div class="border border-base-content/5 overflow-x-auto">
                <table class="table table-auto md:table-fixed w-full table-zebra">
                    <thead class="text-xs uppercase bg-base-300">
                        <tr>
                            <th scope="col" class="w-[10%]">@sortablelink('id', 'ID')</th>
                            <th scope="col">@sortablelink('name', 'Name')</th>
                            <th scope="col">@sortablelink('classes', 'Classes')</th>
                            <th scope="col" class="w-[15%]">@sortablelink('category', 'Category')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($abilities as $ability)
                            <tr>
                                <td>{{ $ability->id }}</td>
                                <td>
                                    <a href="{{ route('aa.show', $ability) }}" class="text-base link-info link-hover">
                                        {{ $ability->name }}
                                    </a>
                                </td>
                                <td>
                                    @php
                                        if (($ability->classes ?? 0) === 65535) {
                                            $abilityClassesStr = 'ALL';
                                        } else {
                                            $abilityClasses = [];
                                            foreach (config('everquest.classes_bit_abbr') as $bit => $label) {
                                                if ($ability->classes & $bit) {
                                                    $abilityClasses[] = $label;
                                                }
                                            }
                                            $abilityClassesStr = implode(', ', $abilityClasses);
                                        }
                                    @endphp
                                    {{ $abilityClassesStr }}
                                </td>
                                <td>{{ config('everquest.aa_categories.' . $ability->category) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $abilities->onEachSide(2)->links() }}
        @endif
    </div>
@endsection
