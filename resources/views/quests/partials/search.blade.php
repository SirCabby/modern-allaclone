<form method="get" action="{{ route('quests.index') }}" class="mb-6">
    <div class="space-y-4">
        <div>
            <input type="text" id="name" name="name" value="{{ request('name') }}" class="w-full input"
                placeholder="Searches quests by NPC or script name" />
        </div>
        <div class="flex flex-wrap gap-4">
            <div class="flex flex-col">
                <label class="select">
                    <span class="label">Zone</span>
                    <select name="zone" id="zone" class="select">
                        <option value="">-</option>
                        <option value="global" {{ request('zone') === 'global' ? 'selected' : '' }}>Global scripts</option>
                        @foreach ($zones as $zoneOption)
                            <option value="{{ $zoneOption->short_name }}" {{ request('zone') === $zoneOption->short_name ? 'selected' : '' }}>
                                {{ $zoneOption->long_name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div class="flex flex-col">
                <label class="select">
                    <span class="label">Language</span>
                    <select name="language" id="language" class="select">
                        <option value="">-</option>
                        <option value="pl" {{ request('language') === 'pl' ? 'selected' : '' }}>Perl</option>
                        <option value="lua" {{ request('language') === 'lua' ? 'selected' : '' }}>Lua</option>
                    </select>
                </label>
            </div>
        </div>
        <div class="pt-4">
            <button type="submit" class="btn btn-soft">
                Search
            </button>
            <a href="{{ route('quests.index') }}" class="btn btn-soft btn-error">
                Reset
            </a>
        </div>
    </div>
</form>
