<form @submit.prevent class="flex items-center space-x-2 w-full justify-end">
    <div x-data="eqsearch()" @click.away="results = []" class="relative w-full max-w-xs">
        <input type="text" placeholder="Search NPCs, Items, Tasks..."
            pattern="[A-Za-z0-9 -_.'`]*"
            x-model="query"
            @input.debounce.600ms="load"
            @focus="if (query.length > 0) load()"
            @keydown.enter.prevent
            @keydown="
                const allowed = /^[a-zA-Z0-9 -_.'`]$/;
                if (!allowed.test($event.key) && !['Backspace', 'Tab', 'ArrowLeft', 'ArrowRight', 'Delete'].includes($event.key)) {
                    $event.preventDefault();
                }
            "
            class="input input-bordered text-white w-full focus:outline-none"
            autocomplete="off"
        />
        <div x-show="results.length > 0 || loading"
            class="absolute right-0 mt-2 z-50
                    max-h-90 overflow-y-auto scrollbar-thin scrollbar-thumb-accent scrollbar-track-base-300
                    sm:max-h-none sm:overflow-visible sm:scrollbar-none
                    sm:min-w-full sm:w-screen sm:max-w-md lg:max-w-2xl xl:max-w-4xl">
            <div class="bg-base-200 border border-base-content/50 rounded shadow-lg p-2">
                <template x-if="loading">
                    <div class="flex justify-center items-center p-2">
                        <svg class="animate-spin h-5 w-5 text-sky-400" xmlns="http://www.w3.org/2000/svg"
                            fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10"
                                stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                        </svg>
                    </div>
                </template>
                <ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                    <template x-for="result in results" :key="result.id">
                        <li class="hover:bg-base-100 cursor-pointer rounded p-1 px-2 transition">
                            <a :href="result.url" class="flex justify-between items-center space-x-2">
                                {{-- `sub` is the entity's own short handle -- a zone's short_name --
                                     kept beside the name rather than inside it. --}}
                                <span class="flex items-baseline gap-1 min-w-0">
                                    <span class="block text-sm text-base-content truncate"
                                        x-text="result.name"></span>
                                    <template x-if="result.sub">
                                        <span class="text-xs uppercase text-base-content/50 whitespace-nowrap"
                                            x-text="result.sub"></span>
                                    </template>
                                </span>
                                <span class="text-xs text-accent whitespace-nowrap"
                                    :class="{
                                        'text-soft-accent': result.type === 'zone',
                                        'text-primary': result.type === 'item',
                                        'text-info': result.type === 'npc',
                                        'text-warning': result.type === 'spell',
                                        'text-success': result.type === 'recipe',
                                        'text-secondary': result.type === 'faction',
                                        'text-error': result.type === 'quest',
                                        'text-purple-400': result.type === 'task',
                                    }"
                                    x-text="result.type"></span>
                            </a>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </div>
</form>
