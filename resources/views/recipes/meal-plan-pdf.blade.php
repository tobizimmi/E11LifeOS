{{-- Weekly meal plan PDF; accent from the household's PDF design. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @php($accent = $theme['accent'])
        @page { margin: 16mm 16mm 20mm; }
        body { font-size: 10.5pt; color: #1d1d1f; line-height: 1.4; }
        .eyebrow { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.2pt; color: {{ $accent }}; margin: 0 0 4pt; }
        h1 { font-size: 24pt; line-height: 1.1; letter-spacing: -0.4pt; margin: 0 0 18pt; font-weight: bold; }
        .day { border-top: 0.8pt solid #d2d2d7; padding: 9pt 0; page-break-inside: avoid; }
        .day-head { font-size: 11pt; font-weight: bold; margin: 0 0 4pt; }
        .day-date { font-weight: normal; color: #86868b; font-size: 8.5pt; }
        .meal { margin: 2pt 0; }
        .meal-type { display: inline-block; min-width: 70pt; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.6pt; color: {{ $accent }}; font-weight: bold; }
        .empty { color: #a1a1a6; font-style: italic; }
        .note { color: #86868b; font-size: 8.5pt; }
    </style>
</head>
<body>
    <p class="eyebrow">{{ __('Meal plan') }}</p>
    <h1>{{ $start->locale(app()->getLocale())->isoFormat('D. MMM') }} – {{ $end->locale(app()->getLocale())->isoFormat('D. MMM YYYY') }}</h1>

    @foreach ($days as $day)
        <div class="day">
            <p class="day-head">{{ $day['date']->locale(app()->getLocale())->isoFormat('dddd') }} <span class="day-date">{{ $day['date']->locale(app()->getLocale())->isoFormat('D. MMM') }}</span></p>
            @forelse ($day['entries'] as $entry)
                <p class="meal"><span class="meal-type">{{ \App\Models\MealPlan::mealTypeLabel($entry->meal_type) }}</span> {{ $entry->recipe?->title ?? $entry->title }}@if ($entry->servings) <span class="note">· {{ trans_choice(':count serving|:count servings', $entry->servings) }}</span>@endif</p>
            @empty
                <p class="meal empty">{{ __('Nothing planned') }}</p>
            @endforelse
        </div>
    @endforeach
</body>
</html>
