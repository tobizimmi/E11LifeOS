{{-- Cookbook PDF: cover, table of contents, then one recipe per page in the design of the PDF editor. --}}
@php
    $accent = $theme['accent'];
    $small = $layout['paper'] === 'A5';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @include('recipes.partials.pdf-styles')
        .cover { text-align: left; padding-top: {{ $small ? '30mm' : '50mm' }}; }
        .cover-photo { width: 100%; border-radius: 12pt; margin-bottom: 24pt; }
        .cover-eyebrow { font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.6pt; color: {{ $accent }}; margin: 0 0 6pt; }
        .cover-title { font-size: {{ $small ? '30pt' : '42pt' }}; line-height: 1.05; letter-spacing: -0.8pt; font-weight: bold; margin: 0; }
        .cover-meta { font-size: 10pt; color: #86868b; margin-top: 10pt; }
        .toc { width: 100%; border-collapse: collapse; }
        .toc td { padding: 5pt 0; border-bottom: 0.5pt solid #e5e5ea; }
        .toc-number { width: 22pt; color: {{ $accent }}; font-weight: bold; }
        .toc-meta { text-align: right; color: #86868b; font-size: 8pt; white-space: nowrap; }
        .page { page-break-before: always; }
    </style>
</head>
<body>
    <div class="cover">
        @if ($cover)
            <img src="{{ $cover }}" alt="" class="cover-photo">
        @endif
        <p class="cover-eyebrow">{{ __('Cookbook') }}</p>
        <h1 class="cover-title">{{ $title }}</h1>
        <p class="cover-meta">{{ trans_choice(':count recipe|:count recipes', $pages->count()) }} · {{ $household->name }}</p>
    </div>

    <div class="page">
        <h2>{{ __('Contents') }}</h2>
        <table class="toc">
            @foreach ($pages as $index => $page)
                <tr>
                    <td class="toc-number">{{ $index + 1 }}</td>
                    <td>{{ $page['recipe']->title }}</td>
                    <td class="toc-meta">{{ $page['recipe']->totalTime() ? $page['recipe']->totalTime().' '.__('min') : '' }}</td>
                </tr>
            @endforeach
        </table>
    </div>

    @foreach ($pages as $page)
        <div class="page">
            @include('recipes.partials.pdf-recipe', $page)
        </div>
    @endforeach
</body>
</html>
