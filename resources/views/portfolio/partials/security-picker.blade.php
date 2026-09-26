{{--
    Search field for a security (name, symbol, ISIN, WKN). Calls searchSecurities() of the
    component and writes the choice into $model (symbol) and $nameModel (name).
--}}
<div x-data="{ query: '', results: [], open: false, loading: false,
               async search() { if (this.query.trim().length < 2) { this.results = []; return; } this.loading = true; this.results = await $wire.searchSecurities(this.query); this.loading = false; this.open = true; },
               choose(item) { $wire.set('{{ $model }}', item.symbol); @isset($nameModel) $wire.set('{{ $nameModel }}', item.name); @endisset this.query = ''; this.results = []; this.open = false; } }"
     class="relative" x-on:click.outside="open = false">
    <x-text-input :id="$id" type="search" x-model="query" x-on:input.debounce.400ms="search()" x-on:keydown.escape="open = false" class="block w-full" autocomplete="off" :placeholder="$placeholder ?? __('Name, symbol, ISIN or WKN')" />
    <span x-show="loading" class="absolute end-3 top-2.5 text-xs text-gray-400">…</span>
    <ul x-show="open && results.length" x-cloak class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-lg bg-white py-1 text-sm shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <template x-for="item in results" :key="item.symbol">
            <li>
                <button type="button" x-on:click="choose(item)" class="flex w-full items-baseline gap-2 px-3 py-2 text-start hover:bg-gray-50 dark:hover:bg-gray-700">
                    <span class="font-medium text-gray-900 dark:text-gray-100" x-text="item.symbol"></span>
                    <span class="min-w-0 flex-1 truncate text-gray-600 dark:text-gray-300" x-text="item.name"></span>
                    <span class="shrink-0 text-xs text-gray-400" x-text="item.exchange"></span>
                </button>
            </li>
        </template>
    </ul>
    <p x-show="open && !loading && results.length === 0 && query.length >= 2" x-cloak class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Nothing found – you can also type the symbol directly.') }}</p>
</div>
