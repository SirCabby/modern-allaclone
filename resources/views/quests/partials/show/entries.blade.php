{{-- One block of the walkthrough: actions in the order the script runs them, and
     a nested block for each branch. Included recursively, so $entries is the only
     thing that changes on the way down. --}}
@php
    // The dot standing in for a category label, so a row's shape reads before
    // its words do: what you get, what it costs, what it changes.
    $dots = [
        'say' => 'bg-sky-400',
        'emote' => 'bg-sky-400/50',
        'give' => 'bg-emerald-400',
        'reward' => 'bg-amber-400',
        'task' => 'bg-violet-400',
        'flag' => 'bg-slate-400',
        'spawn' => 'bg-rose-400',
        'move' => 'bg-cyan-400',
        'combat' => 'bg-red-400',
    ];
@endphp
@foreach ($entries as $entry)
    @if ($entry['type'] === 'branch')
        <div class="border-l border-base-content/15 pl-3">
            <div class="text-sm">
                <span class="text-xs uppercase tracking-wide text-accent">{{ $entry['joiner'] }}</span>
                @include('quests.partials.show.segments', ['segments' => $entry['condition']])
            </div>
            <div class="mt-1 flex flex-col gap-1.5">
                @include('quests.partials.show.entries', ['entries' => $entry['entries']])
            </div>
        </div>
    @else
        <div class="flex items-start gap-2 text-sm {{ $entry['minor'] ? 'text-base-content/40' : '' }}">
            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ $dots[$entry['kind']] ?? 'bg-base-content/25' }}"></span>
            <div class="min-w-0">
                <div>@include('quests.partials.show.segments', ['segments' => $entry['segments']])</div>
                @if ($entry['quote'])
                    <div class="mt-1 border-l-2 border-base-content/15 pl-2 italic text-base-content/80">
                        &ldquo;{{ $entry['quote'] }}&rdquo;
                    </div>
                @endif
            </div>
        </div>
    @endif
@endforeach
