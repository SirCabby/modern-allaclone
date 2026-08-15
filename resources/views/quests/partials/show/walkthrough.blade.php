{{-- The script read back as English. Every word of it is derived from the source
     shown alongside, which stays the authority: where the two disagree, the
     script is right. --}}
@if ($walkthrough->hasScenes())
    <div class="flex flex-col gap-3">
        @foreach ($walkthrough->scenes() as $scene)
            <div class="rounded border border-base-content/10 {{ $scene['aside'] ? 'opacity-60' : '' }}">
                <div class="flex flex-wrap items-center justify-between gap-2 bg-neutral px-3 py-2">
                    <span class="text-sm font-medium text-neutral-content">{{ $scene['title'] }}</span>
                    @if ($scene['note'])
                        <span class="font-mono text-xs text-base-content/40">{{ $scene['note'] }}</span>
                    @endif
                </div>
                <div class="flex flex-col gap-1.5 px-3 py-2">
                    @include('quests.partials.show.entries', ['entries' => $scene['entries']])
                </div>
            </div>
        @endforeach
    </div>

    <p class="mt-3 text-xs text-base-content/40">
        Written from the script by this site, not by hand.
        @if ($walkthrough->coverage() !== null && $walkthrough->coverage() < 100)
            {{ $walkthrough->coverage() }}% of its lines were recognised; the rest are shown as source.
        @endif
    </p>
@else
    <p class="italic text-base-content/50">
        Nothing in this script could be read as quest steps &mdash; see the script itself.
    </p>
@endif
