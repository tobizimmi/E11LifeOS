<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $recipe->title }} · {{ config('app.name') }}</title>
        <meta property="og:title" content="{{ $recipe->title }}">
        @if ($recipe->description)
            <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($recipe->description, 200) }}">
        @endif
        @if ($recipe->image_path)
            <meta property="og:image" content="{{ route('recipes.public.image', $recipe->public_token) }}">
        @endif
        <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
        <script type="application/ld+json">{!! json_encode(\App\Support\RecipeSchema::toArray($recipe, $recipe->image_path ? route('recipes.public.image', $recipe->public_token) : null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
        @vite(['resources/css/app.css'])
        <style>
            /* Press feedback: instant on pointer-down, the foundation of a direct-feeling UI. */
            .pressable { transition: transform 120ms cubic-bezier(.2,.8,.2,1), background-color 120ms ease; touch-action: manipulation; }
            .pressable:active { transform: scale(.96); }
            /* Tap an ingredient or step to check it off while cooking. */
            .cook-item { cursor: pointer; transition: opacity 160ms ease; }
            .cook-item[aria-checked="true"] .cook-label { text-decoration: line-through; opacity: .5; }
            .cook-item[aria-checked="true"] .cook-box { background: currentColor; border-color: currentColor; }
            .cook-box { transition: background-color 140ms ease, border-color 140ms ease; }
            .cook-tick { opacity: 0; transition: opacity 140ms ease; }
            .cook-item[aria-checked="true"] .cook-tick { opacity: 1; }
            @media (prefers-reduced-motion: reduce) {
                .pressable, .cook-item, .cook-box, .cook-tick { transition: none; }
                .pressable:active { transform: none; }
            }
            @media print {
                .no-print { display: none !important; }
                body { background: #fff !important; color: #000 !important; }
                .print-plain { box-shadow: none !important; background: #fff !important; }
                a[href]::after { content: ""; }
            }
        </style>
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
        {{-- Floating translucent header: keeps the title and the key actions reachable while scrolling. --}}
        <header class="no-print sticky top-0 z-20 border-b border-gray-200/60 bg-gray-100/80 backdrop-blur-xl dark:border-gray-700/60 dark:bg-gray-900/70">
            <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-2.5">
                <span class="min-w-0 flex-1 truncate font-semibold tracking-tight">{{ $recipe->title }}</span>
                <button type="button" data-print class="pressable inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-xs ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">🖨️ <span class="hidden sm:inline">{{ __('Print') }}</span></button>
            </div>
        </header>

        <article class="mx-auto max-w-3xl space-y-6 px-4 py-6 sm:py-10">
            <div class="print-plain overflow-hidden rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                @if ($recipe->image_path)
                    <img src="{{ route('recipes.public.image', $recipe->public_token) }}" alt="{{ $recipe->title }}" class="max-h-[26rem] w-full object-cover" />
                @endif
                <div class="space-y-3 p-5 sm:p-6">
                    <h1 class="text-3xl font-bold leading-tight tracking-tight sm:text-4xl">{{ $recipe->title }}</h1>
                    @if ($recipe->description)
                        <p class="whitespace-pre-line leading-relaxed text-gray-700 dark:text-gray-300">{{ $recipe->description }}</p>
                    @endif
                    <p class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-gray-600 dark:text-gray-400">
                        @if ($recipe->prep_time)
                            <span>{{ __('Prep') }}: <strong class="font-semibold text-gray-900 dark:text-gray-100">{{ $recipe->prep_time }} {{ __('min') }}</strong></span>
                        @endif
                        @if ($recipe->cook_time)
                            <span>{{ __('Cooking') }}: <strong class="font-semibold text-gray-900 dark:text-gray-100">{{ $recipe->cook_time }} {{ __('min') }}</strong></span>
                        @endif
                        @if ($recipe->difficultyLabel())
                            <span>{{ __('Difficulty') }}: <strong class="font-semibold text-gray-900 dark:text-gray-100">{{ $recipe->difficultyLabel() }}</strong></span>
                        @endif
                    </p>
                </div>
            </div>

            <div class="grid gap-6 md:grid-cols-5">
                <section class="print-plain rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800 md:col-span-2">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold tracking-tight">{{ __('Ingredients') }}</h2>
                        @if ($servings)
                            <form method="get" class="no-print flex items-center gap-1 text-sm" aria-label="{{ __('Servings') }}">
                                <button name="servings" value="{{ max(1, $servings - 1) }}" class="pressable h-9 w-9 rounded-full border border-gray-300 text-lg leading-none dark:border-gray-600" aria-label="{{ __('Fewer servings') }}">−</button>
                                <span class="min-w-[5.5rem] text-center tabular-nums">{{ trans_choice(':count serving|:count servings', $servings) }}</span>
                                <button name="servings" value="{{ min(99, $servings + 1) }}" class="pressable h-9 w-9 rounded-full border border-gray-300 text-lg leading-none dark:border-gray-600" aria-label="{{ __('More servings') }}">+</button>
                            </form>
                        @endif
                    </div>
                    <p class="no-print mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('Tap to check off') }}</p>
                    <ul class="mt-3 space-y-0.5 text-indigo-600 dark:text-indigo-400">
                        @foreach ($ingredients as $ingredient)
                            @if ($ingredient['isSection'])
                                <li class="pt-3 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $ingredient['name'] }}</li>
                            @else
                                <li class="cook-item -mx-2 flex items-start gap-2.5 rounded-lg px-2 py-1.5 text-gray-900 hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-700/40" role="checkbox" aria-checked="false" tabindex="0">
                                    <span class="cook-box mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md border-2 border-gray-300 text-white dark:border-gray-600" aria-hidden="true"><svg class="cook-tick h-3 w-3" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.5l2.5 2.5 4.5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                                    <span class="cook-label"><span class="font-medium">{{ trim($ingredient['amount'].' '.$ingredient['unit']) }}</span> {{ $ingredient['name'] }}</span>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </section>

                <section class="print-plain rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800 md:col-span-3">
                    <h2 class="text-lg font-semibold tracking-tight">{{ __('Preparation') }}</h2>
                    <ol class="mt-3 space-y-2.5 text-indigo-600 dark:text-indigo-400">
                        @foreach ($steps as $number => $step)
                            <li class="cook-item -mx-2 flex gap-3 rounded-lg px-2 py-2 hover:bg-gray-50 dark:hover:bg-gray-700/40" role="checkbox" aria-checked="false" tabindex="0">
                                <span class="cook-box flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-sm font-semibold text-white dark:bg-indigo-500">{{ $number + 1 }}</span>
                                <p class="cook-label whitespace-pre-line pt-0.5 leading-relaxed text-gray-900 dark:text-gray-100">{{ $step }}</p>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>

            @if ($nutrition !== [])
                <section class="print-plain rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h2 class="text-lg font-semibold tracking-tight">{{ __('Nutrition per serving') }}</h2>
                    <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                        @foreach ($nutrition as $key => $value)
                            <div class="rounded-xl bg-gray-50 p-3 text-center dark:bg-gray-900/60">
                                <dt class="text-xs text-gray-500">{{ \App\Models\Recipe::nutritionLabel($key)[0] }}</dt>
                                <dd class="text-xl font-semibold tabular-nums">{{ str_replace('.', ',', (string) $value) }} <span class="text-sm font-normal text-gray-500">{{ \App\Models\Recipe::nutritionLabel($key)[1] }}</span></dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif

            <p class="text-center text-xs text-gray-500">{{ __('Shared via :app', ['app' => config('app.name')]) }}</p>
        </article>

        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            document.querySelectorAll('[data-print]').forEach(function (button) {
                button.addEventListener('click', function () { window.print(); });
            });

            // Tap-to-check for ingredients and steps; remembered per device so the list survives a reload.
            (function () {
                var storeKey = 'recipe-checks:' + location.pathname;
                var checked = {};
                try { checked = JSON.parse(localStorage.getItem(storeKey) || '{}'); } catch (e) {}

                document.querySelectorAll('.cook-item').forEach(function (item, index) {
                    var id = (item.closest('ol') ? 's' : 'i') + index;
                    if (checked[id]) { item.setAttribute('aria-checked', 'true'); }

                    function toggle() {
                        var on = item.getAttribute('aria-checked') !== 'true';
                        item.setAttribute('aria-checked', on ? 'true' : 'false');
                        checked[id] = on;
                        try { localStorage.setItem(storeKey, JSON.stringify(checked)); } catch (e) {}
                    }

                    item.addEventListener('click', toggle);
                    item.addEventListener('keydown', function (e) {
                        if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); toggle(); }
                    });
                });
            })();
        </script>
    </body>
</html>
