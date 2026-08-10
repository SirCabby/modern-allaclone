@extends('layouts.default')
@section('title', 'Pets' . ' - ' . $selClassName ?? 'Unknown')

@section('content')
    <label class="select">
        <span class="label">Classes</span>
        <select id="select-pet-class">
            @foreach (config('everquest.pet_classes') as $k => $v)
                <option value="{{ $k }}" {{ request()->route('id') == $k ? 'selected' : '' }}>{{ $v }}</option>
            @endforeach
        </select>
    </label>
    @if ($bl_pet_data->isNotEmpty())
        @include('pets.partials.beastlord')
    @endif
    @if ($pets->isNotEmpty())
    <div class="border border-base-content/5 overflow-x-auto mt-6">
        <table class="table table-auto md:table-fixed w-full table-zebra" data-sortable>
            <thead class="text-xs uppercase bg-base-300">
                <tr>
                    <th scope="col" class="w-[5%]" data-sort="number">Lvl</th>
                    <th scope="col" class="w-[20%]" data-sort>Spell</th>
                    <th scope="col" class="w-[10%]" data-sort>Name</th>
                    <th scope="col" class="w-[10%] hidden lg:table-cell" data-sort>Race</th>
                    <th scope="col" class="w-[20%]" data-sort>Class</th>
                    <th scope="col" class="w-[10%] hidden md:table-cell" data-sort="number">HP</th>
                    <th scope="col" class="w-[10%] hidden lg:table-cell" data-sort="number">AC</th>
                    {{-- "5-10" parses as 5, so the column sorts on the bottom of
                         the range, which is the number it reads. --}}
                    <th scope="col" class="w-[15%] hidden lg:table-cell" data-sort="number">DMG</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pets as $pet)
                    @if ($pet->pets === null || $pet->npcs === null)
                        @continue
                    @endif
                    <tr>
                        <td scope="row">{{ $pet->npcs ? $pet->npcs->level : 'N/A' }}</td>
                        <td data-sort-value="{{ $pet->name }}">
                            <x-spell-link
                                :spell_id="$pet->id"
                                :spell_name="$pet->name"
                                :spell_icon="$pet->new_icon"
                                spell_class="flex text-base"
                            />
                        </td>
                        <td data-sort-value="{{ $pet->pets->type }}">
                            <x-pet-link
                                :pet_id="$pet->pets->id"
                                :pet_name="$pet->pets->type"
                                pet_class="flex"
                            />
                        </td>
                        <td class="hidden lg:table-cell">
                            {{ config('everquest.db_races.' . $pet->npcs->race) ?? 'Unknown' }}
                        </td>
                        <td>{{ config('everquest.classes.' . $pet->npcs->class) }}</td>
                        <td class="hidden md:table-cell">{{ $pet->npcs->hp }}</td>
                        <td class="hidden lg:table-cell">{{ $pet->npcs->AC }}</td>
                        <td class="hidden lg:table-cell">
                            {{ $pet->npcs->mindmg }}-{{ $pet->npcs->maxdmg }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
@endsection
