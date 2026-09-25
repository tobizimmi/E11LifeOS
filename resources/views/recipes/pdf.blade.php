<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $recipe->title }}</title>
    <style>
        @page { margin: 18mm 16mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; color: #1f2937; line-height: 1.45; }
        h1 { font-size: 20pt; margin: 0 0 4pt; }
        h2 { font-size: 12.5pt; margin: 14pt 0 6pt; color: #4338ca; border-bottom: 1px solid #e5e7eb; padding-bottom: 3pt; }
        .meta { color: #6b7280; font-size: 9.5pt; margin-bottom: 8pt; }
        .photo { width: 100%; max-height: 230pt; object-fit: cover; margin-bottom: 10pt; }
        .columns { width: 100%; border-collapse: collapse; }
        .columns td { vertical-align: top; }
        .ingredients { width: 36%; padding-right: 14pt; }
        .ingredients table { width: 100%; border-collapse: collapse; }
        .ingredients td { padding: 2pt 0; border-bottom: 1px solid #f3f4f6; }
        .amount { white-space: nowrap; font-weight: bold; padding-right: 6pt !important; width: 30%; }
        .section { font-size: 8.5pt; text-transform: uppercase; color: #6b7280; font-weight: bold; padding-top: 7pt !important; border-bottom: none !important; }
        ol { margin: 0; padding-left: 16pt; }
        li { margin-bottom: 6pt; }
        .nutrition td { padding: 3pt 8pt 3pt 0; }
        .footer { margin-top: 16pt; font-size: 8.5pt; color: #9ca3af; }
    </style>
</head>
<body>
    @if ($image)
        <img src="{{ $image }}" alt="" class="photo">
    @endif

    <h1>{{ $recipe->title }}</h1>
    <div class="meta">
        {{ collect([
            $servings ? trans_choice(':count serving|:count servings', $servings) : null,
            $recipe->prep_time ? __('Prep').': '.$recipe->prep_time.' '.__('min') : null,
            $recipe->cook_time ? __('Cooking').': '.$recipe->cook_time.' '.__('min') : null,
            $recipe->difficultyLabel(),
            $recipe->categories->pluck('name')->implode(', ') ?: null,
        ])->filter()->implode(' · ') }}
    </div>

    @if ($recipe->description)
        <p>{!! nl2br(e($recipe->description)) !!}</p>
    @endif

    <table class="columns">
        <tr>
            <td class="ingredients">
                <h2>{{ __('Ingredients') }}</h2>
                <table>
                    @foreach ($ingredients as $ingredient)
                        @if ($ingredient['isSection'])
                            <tr><td colspan="2" class="section">{{ $ingredient['name'] }}</td></tr>
                        @else
                            <tr><td class="amount">{{ trim($ingredient['amount'].' '.$ingredient['unit']) }}</td><td>{{ $ingredient['name'] }}</td></tr>
                        @endif
                    @endforeach
                </table>
            </td>
            <td>
                <h2>{{ __('Preparation') }}</h2>
                <ol>
                    @foreach ($steps as $step)
                        <li>{!! nl2br(e($step)) !!}</li>
                    @endforeach
                </ol>
            </td>
        </tr>
    </table>

    @if ($nutrition !== [])
        <h2>{{ __('Nutrition per serving') }}</h2>
        <table class="nutrition">
            <tr>
                @foreach ($nutrition as $key => $value)
                    <td><strong>{{ str_replace('.', ',', (string) $value) }} {{ \App\Models\Recipe::nutritionLabel($key)[1] }}</strong> {{ \App\Models\Recipe::nutritionLabel($key)[0] }}</td>
                @endforeach
            </tr>
        </table>
    @endif

    <div class="footer">
        @if ($recipe->source_url)
            {{ __('Source') }}: {{ $recipe->source_url }} ·
        @endif
        {{ config('app.name') }} · {{ now()->format('d.m.Y') }}
    </div>
</body>
</html>
