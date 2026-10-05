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
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
        <article class="max-w-3xl mx-auto px-4 py-6 sm:py-10 space-y-6">
            <div class="overflow-hidden rounded-lg bg-white dark:bg-gray-800 shadow-xs">
                @if ($recipe->image_path)
                    <img src="{{ route('recipes.public.image', $recipe->public_token) }}" alt="{{ $recipe->title }}" class="max-h-[26rem] w-full object-cover" />
                @endif
                <div class="p-5 sm:p-6 space-y-3">
                    <h1 class="text-3xl font-bold">{{ $recipe->title }}</h1>
                    @if ($recipe->description)
                        <p class="whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $recipe->description }}</p>
                    @endif
                    <p class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-gray-600 dark:text-gray-400">
                        @if ($recipe->prep_time)
                            <span>{{ __('Prep') }}: <strong>{{ $recipe->prep_time }} {{ __('min') }}</strong></span>
                        @endif
                        @if ($recipe->cook_time)
                            <span>{{ __('Cooking') }}: <strong>{{ $recipe->cook_time }} {{ __('min') }}</strong></span>
                        @endif
                        @if ($recipe->difficultyLabel())
                            <span>{{ __('Difficulty') }}: <strong>{{ $recipe->difficultyLabel() }}</strong></span>
                        @endif
                    </p>
                </div>
            </div>

            <div class="grid gap-6 md:grid-cols-5">
                <section class="md:col-span-2 rounded-lg bg-white dark:bg-gray-800 p-5 shadow-xs">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold">{{ __('Ingredients') }}</h2>
                        @if ($servings)
                            <form method="get" class="flex items-center gap-1 text-sm" aria-label="{{ __('Servings') }}">
                                <button name="servings" value="{{ max(1, $servings - 1) }}" class="h-8 w-8 rounded-full border border-gray-300 dark:border-gray-600" aria-label="{{ __('Fewer servings') }}">−</button>
                                <span class="min-w-[5.5rem] text-center">{{ trans_choice(':count serving|:count servings', $servings) }}</span>
                                <button name="servings" value="{{ min(99, $servings + 1) }}" class="h-8 w-8 rounded-full border border-gray-300 dark:border-gray-600" aria-label="{{ __('More servings') }}">+</button>
                            </form>
                        @endif
                    </div>
                    <ul class="mt-3 space-y-1.5">
                        @foreach ($ingredients as $ingredient)
                            @if ($ingredient['isSection'])
                                <li class="pt-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $ingredient['name'] }}</li>
                            @else
                                <li><span class="font-medium">{{ trim($ingredient['amount'].' '.$ingredient['unit']) }}</span> {{ $ingredient['name'] }}</li>
                            @endif
                        @endforeach
                    </ul>
                </section>

                <section class="md:col-span-3 rounded-lg bg-white dark:bg-gray-800 p-5 shadow-xs">
                    <h2 class="text-lg font-semibold">{{ __('Preparation') }}</h2>
                    <ol class="mt-3 space-y-4">
                        @foreach ($steps as $number => $step)
                            <li class="flex gap-3">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-sm font-semibold text-white">{{ $number + 1 }}</span>
                                <p class="whitespace-pre-line pt-0.5">{{ $step }}</p>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>

            @if ($nutrition !== [])
                <section class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-xs">
                    <h2 class="text-lg font-semibold">{{ __('Nutrition per serving') }}</h2>
                    <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                        @foreach ($nutrition as $key => $value)
                            <div class="rounded-md bg-gray-50 dark:bg-gray-900/60 p-3 text-center">
                                <dt class="text-xs text-gray-500">{{ \App\Models\Recipe::nutritionLabel($key)[0] }}</dt>
                                <dd class="text-xl font-semibold">{{ str_replace('.', ',', (string) $value) }} <span class="text-sm font-normal text-gray-500">{{ \App\Models\Recipe::nutritionLabel($key)[1] }}</span></dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif

            <p class="text-center text-xs text-gray-500">{{ __('Shared via :app', ['app' => config('app.name')]) }}</p>
        </article>
    </body>
</html>
