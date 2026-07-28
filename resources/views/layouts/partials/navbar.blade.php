@php
    $ddActive = Route::is('recipes.*', 'tasks.*', 'factions.*', 'pets.*');
@endphp
<div id="navbar-trigger" class="h-0"></div>
<nav class="navbar bg-neutral mb-3 sticky top-0 z-50">
    <div class="container mx-auto px-4 flex items-center justify-between w-full">

        <div class="flex items-center xl:w-1/3 w-auto">
            <a href="/" class="xl:hidden" title="Modern EQEmu Allaclone">
                <img src="{{ asset('img/eqemu.png') }}" class="min-w-[80px] min-h-[38px]">
            </a>

            <div class="dropdown xl:hidden ml-2">
                <label tabindex="0" class="btn btn-ghost">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </label>
                <ul tabindex="0" class="dropdown-content mt-3 z-[60] menu p-2 shadow bg-base-200 rounded-box w-52">
                    <li><a href="/" class="uppercase {{ Route::is('home') ? 'bg-base-300' : '' }}"
                            title="{{ config('app.name') }}">/</a></li>
                    <li><a href="{{ route('zones.index') }}"
                            class="uppercase {{ Route::is('zones.*') ? 'bg-base-300' : '' }}" title="Zones">Zones</a>
                    </li>
                    <li><a href="{{ route('items.index') }}"
                            class="uppercase {{ Route::is('items.*') ? 'bg-base-300' : '' }}" title="Items">Items</a>
                    </li>
                    <li><a href="{{ route('spells.index') }}"
                            class="uppercase {{ Route::is('spells.*') ? 'bg-base-300' : '' }}" title="Spells">Spells</a>
                    </li>
                    <li><a href="{{ route('aa.index') }}"
                            class="uppercase {{ Route::is('aa.*') ? 'bg-base-300' : '' }}" title="AAs">AAs</a>
                    </li>
                    <li><a href="{{ route('npcs.index') }}"
                            class="uppercase {{ Route::is('npcs.*') ? 'bg-base-300' : '' }}" title="NPCs">NPCs</a>
                    </li>
                    <li><a href="{{ route('quests.index') }}"
                            class="uppercase {{ Route::is('quests.*') ? 'bg-base-300' : '' }}" title="Quests">Quests</a>
                    </li>
                    <li tabindex="0">
                        <details>
                            <summary
                                class="uppercase flex items-center justify-between cursor-pointer {{ $ddActive ? 'bg-base-100' : '' }}"
                                title="More">
                                More
                            </summary>
                            <ul class="pl-4">
                                <li><a href="{{ route('recipes.index') }}"
                                        class="{{ Route::is('recipes.*') ? 'bg-base-300' : '' }}"
                                        title="Recipes">Recipes</a></li>
                                <li><a href="{{ route('tasks.index') }}"
                                        class="{{ Route::is('tasks.*') ? 'bg-base-300' : '' }}"
                                        title="Tasks">Tasks</a></li>
                                <li><a href="{{ route('factions.index') }}"
                                        class="{{ Route::is('factions.*') ? 'bg-base-300' : '' }}"
                                        title="Faction">Faction</a></li>
                                <li><a href="{{ route('pets.index') }}"
                                        class="{{ Route::is('pets.*') ? 'bg-base-300' : '' }}" title="Pets">Pets</a>
                                </li>
                                @if (config('everquest.discovered_items.enable'))
                                    <li><a href="{{ route('discovery.index') }}"
                                            class="{{ Route::is('discovery.index') ? 'bg-base-300' : '' }}"
                                            title="Discovered Items">
                                            Discovered Items
                                        </a>
                                    </li>
                                    <li><a href="{{ route('discovery.leaderboard') }}"
                                            class="{{ Route::is('discovery.*') ? 'bg-base-300' : '' }}"
                                            title="Discovery Leaderboard">
                                            Discovery Leaderboard
                                        </a>
                                    </li>
                                @endif
                                <li class="divider my-1 h-px" role="separator"></li>
                                <li><a href="{{ config('everquest.magelo_base_url') }}" title="{{ config('everquest.magelo_menu_name') }}">
                                        {{ config('everquest.magelo_menu_name') }}
                                    </a>
                                </li>
                            </ul>
                        </details>
                    </li>
                </ul>
            </div>
        </div>

        <div id="eqemu-desktop-logo" class="hidden xl:flex justify-center xl:w-1/3 relative">
            <a href="/" class="block absolute -top-9" title="Modern EQEmu Allaclone">
                <img src="{{ asset('img/eqemu.png') }}" class="w-[158px] h-[76px]">
            </a>
        </div>

        <div class="flex items-center justify-end gap-2 xl:w-1/3 w-full">
            @include('layouts.partials.suggest-search')
            @include('layouts.partials.controls')
        </div>

        <div class="hidden xl:flex space-x-2 absolute left-5 top-1/2 -translate-y-1/2">
            <a href="/" title="{{ config('app.name') }}"
                class="btn btn-ghost uppercase {{ Route::is('home') ? 'btn-active' : '' }}">/</a>
            <a href="{{ route('zones.index') }}" title="Zones"
                class="btn btn-ghost uppercase {{ Route::is('zones.*') ? 'btn-active' : '' }}">Zones</a>
            <a href="{{ route('items.index') }}" title="Items"
                class="btn btn-ghost uppercase {{ Route::is('items.*') ? 'btn-active' : '' }}">Items</a>
            <a href="{{ route('spells.index') }}" title="Spells"
                class="btn btn-ghost uppercase {{ Route::is('spells.*') ? 'btn-active' : '' }}">Spells</a>
            <a href="{{ route('aa.index') }}" title="AAs"
                class="btn btn-ghost uppercase {{ Route::is('aa.*') ? 'btn-active' : '' }}">AAs</a>
            <a href="{{ route('npcs.index') }}" title="NPCs"
                class="btn btn-ghost uppercase {{ Route::is('npcs.*') ? 'btn-active' : '' }}">NPCs</a>
            <a href="{{ route('quests.index') }}" title="Quests"
                class="btn btn-ghost uppercase {{ Route::is('quests.*') ? 'btn-active' : '' }}">Quests</a>
            <div class="dropdown dropdown-hover">
                <label tabindex="0"
                    class="btn btn-ghost uppercase flex items-center gap-1 {{ $ddActive ? 'btn-active' : '' }}"
                    title="More">
                    More
                    <svg class="chevron h-4 w-4 transition-transform duration-200" xmlns="http://www.w3.org/2000/svg"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </label>
                <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-52">
                    <li><a href="{{ route('recipes.index') }}"
                            class="{{ Route::is('recipes.*') ? 'active bg-base-200' : '' }}"
                            title="Recipes">Recipes</a></li>
                    @if (config('everquest.tasks.enable'))
                        <li><a href="{{ route('tasks.index') }}"
                                class="{{ Route::is('tasks.*') ? 'active bg-base-200' : '' }}"
                                title="Tasks">Tasks</a>
                        </li>
                    @endif
                    <li><a href="{{ route('factions.index') }}"
                            class="{{ Route::is('factions.*') ? 'active bg-base-200' : '' }}"
                            title="Faction">Faction</a></li>
                    <li><a href="{{ route('pets.index') }}"
                            class="{{ Route::is('pets.*') ? 'active bg-base-200' : '' }}" title="Pets">Pets</a>
                    </li>
                    @if (config('everquest.discovered_items.enable'))
                        <li><a href="{{ route('discovery.index') }}"
                                class="{{ Route::is('discovery.index') ? 'bg-base-200' : '' }}"
                                title="Discovered Items">
                                Discovered Items
                            </a>
                        </li>
                        <li><a href="{{ route('discovery.leaderboard') }}"
                                class="{{ Route::is('discovery.leaderboard') ? 'bg-base-200' : '' }}"
                                title="Discovery Leaderboard">
                                Discovery Leaderboard
                            </a>
                        </li>
                    @endif
                    <li class="divider my-1 h-px" role="separator"></li>
                    <li><a href="{{ config('everquest.magelo_base_url') }}"
                            title="{{ config('everquest.magelo_menu_name') }}">
                            {{ config('everquest.magelo_menu_name') }}
                        </a>
                    </li>
                </ul>
            </div>
        </div>

    </div>
</nav>
