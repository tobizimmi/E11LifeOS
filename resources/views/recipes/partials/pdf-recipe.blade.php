{{-- One recipe of the PDF: calm typography, clear hierarchy and plenty of white space. --}}
@php
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
