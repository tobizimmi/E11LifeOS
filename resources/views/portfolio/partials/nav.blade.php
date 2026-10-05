{{-- Sections of the portfolio ($active: route name of the current section). --}}
@php
    $section = fn (string $route, string $icon, string $label) => [$route => [$icon, $label, route($route)]];
@endphp
<x-section-nav :label="__('Portfolio')" :active="$active" :groups="[
    __('Depot') => $section('portfolio.index', '📈', __('Overview')) + $section('portfolio.transactions', '🧾', __('Transactions')) + $section('portfolio.dividends', '💰', __('Dividends')) + $section('portfolio.savings', '💶', __('Savings plans')) + $section('portfolio.rebalancing', '⚖', __('Rebalancing')) + $section('portfolio.taxes', '🧮', __('Taxes')),
    __('Research') => $section('portfolio.analysis', '📊', __('Analysis')) + $section('portfolio.market', '🧭', __('Market analysis')) + $section('portfolio.recommendations', '🔎', __('Recommendations')) + $section('portfolio.insights', '🕵️', __('Insiders & analysts')) + $section('portfolio.discover', '🌍', __('Discover')) + $section('portfolio.news', '📰', __('News')) + $section('portfolio.instagram', '📸', __('Instagram')) + $section('portfolio.watchlist', '👀', __('Watchlist')) + $section('portfolio.alerts', '🔔', __('Alerts')),
    __('Data') => $section('portfolio.import', '📥', __('Import')) + $section('portfolio.banking', '🏦', __('Bank sync')) + $section('portfolio.settings', '⚙', __('Settings')),
]" />
