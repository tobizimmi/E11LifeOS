{{-- Recipe PDF: calm typography, clear hierarchy and plenty of white space; layout and accent from the PDF editor. --}}
@php
    $accent = $theme['accent'];
    $small = $layout['paper'] === 'A5';
    $total = (int) $recipe->prep_time + (int) $recipe->cook_time;
    $facts = array_filter([
        $servings ? [__('Servings'), (string) $servings] : null,
        $recipe->prep_time ? [__('Prep'), $recipe->prep_time.' '.__('min')] : null,
        $recipe->cook_time ? [__('Cooking'), $recipe->cook_time.' '.__('min')] : null,
        $total && $recipe->prep_time && $recipe->cook_time ? [__('Total'), $total.' '.__('min')] : null,
        $recipe->difficultyLabel() ? [__('Difficulty'), $recipe->difficultyLabel()] : null,
    ]);
    $plainIngredients = collect($ingredients)->reject(fn ($ingredient) => $ingredient['isSection'])->count();
    $eyebrow = $recipe->categories->pluck('name')->implode(' · ');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $recipe->title }}</title>
    <style>
        @page { margin: {{ $small ? '12mm 12mm 16mm' : '16mm 16mm 20mm' }}; }
        body { font-size: {{ $small ? '9.5pt' : '10.5pt' }}; color: #1d1d1f; line-height: 1.5; }
        .photo { width: 100%; border-radius: 10pt; margin-bottom: {{ $small ? '10pt' : '16pt' }}; }
        .photo-small { width: {{ $small ? '34mm' : '46mm' }}; border-radius: 8pt; }
        .eyebrow { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.2pt; color: {{ $accent }}; margin: 0 0 4pt; }
        h1 { font-size: {{ $small ? '19pt' : '26pt' }}; line-height: 1.1; letter-spacing: -0.4pt; margin: 0; font-weight: bold; }
        .lead { font-size: {{ $small ? '9.5pt' : '11pt' }}; color: #3a3a3c; margin: 8pt 0 0; }
        .facts { width: 100%; border-collapse: collapse; margin: {{ $small ? '10pt' : '16pt' }} 0 {{ $small ? '12pt' : '20pt' }}; }
        .facts td { padding: 6pt 10pt 6pt 0; border-top: 0.6pt solid #d2d2d7; border-bottom: 0.6pt solid #d2d2d7; vertical-align: top; }
        .fact-label { display: block; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.8pt; color: #86868b; }
        .fact-value { display: block; font-size: {{ $small ? '9.5pt' : '11pt' }}; font-weight: bold; }
        h2 { font-size: 8pt; text-transform: uppercase; letter-spacing: 1.2pt; color: #86868b; margin: 0 0 8pt; font-weight: bold; }
        .layout { width: 100%; border-collapse: collapse; }
        .layout td { vertical-align: top; }
        .ingredients-cell { width: {{ $small ? '40%' : '36%' }}; padding-right: {{ $small ? '12pt' : '18pt' }}; }
        .card { background: #f5f5f7; border-radius: 10pt; padding: {{ $small ? '9pt 10pt' : '12pt 14pt' }}; }
        .ingredients { width: 100%; border-collapse: collapse; }
        .ingredients td { padding: 3.5pt 0; border-bottom: 0.5pt solid #e5e5ea; vertical-align: top; }
        .ingredients tr.last td { border-bottom: none; }
        .amount { width: 34%; padding-right: 8pt !important; font-weight: bold; white-space: nowrap; }
        .group { font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8pt; color: {{ $accent }}; padding-top: 8pt !important; border-bottom: none !important; }
        .split { width: 100%; border-collapse: collapse; }
        .split td { width: 50%; vertical-align: top; }
        .step { width: 100%; border-collapse: collapse; margin-bottom: {{ $small ? '7pt' : '10pt' }}; page-break-inside: avoid; }
        .step td { vertical-align: top; }
        .step-number { width: {{ $small ? '18pt' : '22pt' }}; }
        .badge { width: {{ $small ? '13pt' : '16pt' }}; height: {{ $small ? '13pt' : '16pt' }}; line-height: {{ $small ? '13pt' : '16pt' }}; border-radius: 8pt; background: {{ $accent }}; color: #ffffff; text-align: center; font-size: {{ $small ? '7pt' : '8pt' }}; font-weight: bold; }
        .nutrition { width: 100%; border-collapse: separate; border-spacing: 6pt 0; margin: 0 -6pt; }
        .nutrition td { background: #f5f5f7; border-radius: 8pt; padding: 7pt 8pt; text-align: center; }
        .nutrition-value { display: block; font-size: {{ $small ? '10pt' : '12pt' }}; font-weight: bold; }
        .nutrition-label { display: block; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #86868b; }
        .section { margin-top: {{ $small ? '14pt' : '20pt' }}; }
        .notes-line { border-bottom: 0.6pt solid #d2d2d7; height: {{ $small ? '16pt' : '20pt' }}; }
        .meta { margin-top: {{ $small ? '14pt' : '20pt' }}; font-size: 7.5pt; color: #86868b; }
        .tag { color: {{ $accent }}; }
    </style>
</head>
<body>
    @if ($image && $layout['photo'] === 'large')
        <img src="{{ $image }}" alt="" class="photo">
    @endif

    <table class="layout">
        <tr>
            <td>
                @if ($eyebrow !== '')
                    <p class="eyebrow">{{ $eyebrow }}</p>
                @endif
                <h1>{{ $recipe->title }}</h1>
                @if ($layout['description'] && $recipe->description)
                    <p class="lead">{!! nl2br(e($recipe->description)) !!}</p>
                @endif
            </td>
            @if ($image && $layout['photo'] === 'small')
                <td style="width: {{ $small ? '36mm' : '50mm' }}; text-align: right;"><img src="{{ $image }}" alt="" class="photo-small"></td>
            @endif
        </tr>
    </table>

    @if ($facts !== [])
        <table class="facts">
            <tr>
                @foreach ($facts as [$label, $value])
                    <td><span class="fact-label">{{ $label }}</span><span class="fact-value">{{ $value }}</span></td>
                @endforeach
            </tr>
        </table>
    @endif

    @php
        $ingredientRows = function (array $list) {
            $rows = '';
            $count = count($list);
            foreach (array_values($list) as $index => $ingredient) {
                $last = $index === $count - 1 ? ' class="last"' : '';
                $rows .= $ingredient['isSection']
                    ? '<tr><td colspan="2" class="group">'.e($ingredient['name']).'</td></tr>'
                    : '<tr'.$last.'><td class="amount">'.e(trim($ingredient['amount'].' '.$ingredient['unit'])).'</td><td>'.e($ingredient['name']).'</td></tr>';
            }

            return $rows;
        };
    @endphp

    @if ($layout['layout'] === 'columns')
        <table class="layout">
            <tr>
                @if ($ingredients !== [])
                    <td class="ingredients-cell">
                        <h2>{{ __('Ingredients') }}</h2>
                        <div class="card"><table class="ingredients">{!! $ingredientRows($ingredients) !!}</table></div>
                    </td>
                @endif
                <td>
                    <h2>{{ __('Preparation') }}</h2>
                    @foreach ($steps as $number => $step)
                        <table class="step"><tr><td class="step-number"><div class="badge">{{ $number + 1 }}</div></td><td>{!! nl2br(e($step)) !!}</td></tr></table>
                    @endforeach
                </td>
            </tr>
        </table>
    @else
        @if ($ingredients !== [])
            <h2>{{ __('Ingredients') }}</h2>
            <div class="card">
                @php($half = (int) ceil(count($ingredients) / 2))
                @if ($plainIngredients > 6)
                    <table class="split"><tr>
                        <td style="padding-right: 12pt;"><table class="ingredients">{!! $ingredientRows(array_slice($ingredients, 0, $half)) !!}</table></td>
                        <td><table class="ingredients">{!! $ingredientRows(array_slice($ingredients, $half)) !!}</table></td>
                    </tr></table>
                @else
                    <table class="ingredients">{!! $ingredientRows($ingredients) !!}</table>
                @endif
            </div>
        @endif
        <div class="section">
            <h2>{{ __('Preparation') }}</h2>
            @foreach ($steps as $number => $step)
                <table class="step"><tr><td class="step-number"><div class="badge">{{ $number + 1 }}</div></td><td>{!! nl2br(e($step)) !!}</td></tr></table>
            @endforeach
        </div>
    @endif

    @if ($layout['nutrition'] && $nutrition !== [])
        <div class="section">
            <h2>{{ __('Nutrition per serving') }}</h2>
            <table class="nutrition">
                <tr>
                    @foreach ($nutrition as $key => $value)
                        <td><span class="nutrition-value">{{ str_replace('.', ',', (string) $value) }} {{ \App\Models\Recipe::nutritionLabel($key)[1] }}</span><span class="nutrition-label">{{ \App\Models\Recipe::nutritionLabel($key)[0] }}</span></td>
                    @endforeach
                </tr>
            </table>
        </div>
    @endif

    @if ($layout['notes'])
        <div class="section">
            <h2>{{ __('Notes') }}</h2>
            @for ($line = 0; $line < ($small ? 4 : 6); $line++)
                <div class="notes-line"></div>
            @endfor
        </div>
    @endif

    @php($tags = $layout['tags'] ? $recipe->tags->pluck('name') : collect())
    @if (($layout['source'] && $recipe->source_url) || $tags->isNotEmpty())
        <p class="meta">
            @if ($tags->isNotEmpty())
                <span class="tag">{{ $tags->map(fn ($tag) => '#'.$tag)->implode('  ') }}</span>@if ($layout['source'] && $recipe->source_url) &nbsp;·&nbsp; @endif
            @endif
            @if ($layout['source'] && $recipe->source_url)
                {{ __('Source') }}: {{ \Illuminate\Support\Str::limit($recipe->source_url, 90) }}
            @endif
        </p>
    @endif
</body>
</html>
