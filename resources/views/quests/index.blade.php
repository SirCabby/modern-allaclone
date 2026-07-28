@extends('layouts.default')
@section('title', 'Quests')

@section('content')
    @include('quests.partials.search')

    @if ($quests->isNotEmpty())
        @include('quests.partials.index.index-table')

        {{ $quests->onEachSide(2)->links() }}
    @else
        <div class="alert alert-warning alert-soft">
            <span>
                No quest scripts found.
                @if (!request()->hasAny(['name', 'zone', 'language']))
                    If this server has quests, build the index with <code>php artisan quests:index</code>.
                @endif
            </span>
        </div>
    @endif
@endsection
