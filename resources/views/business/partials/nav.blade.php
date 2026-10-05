{{-- Sections of the small business area ($active: route name of the current section). --}}
@php
    $section = fn (string $route, string $icon, string $label) => [$route => [$icon, $label, route($route)]];
@endphp
<x-section-nav :label="__('Small business')" :active="$active" :groups="[
    __('Sales') => $section('business.index', '🧾', __('Documents')) + $section('business.recurring', '🔁', __('Recurring')) + $section('business.time', '⏱', __('Time tracking')) + $section('business.customers', '👥', __('Customers')) + $section('business.products', '📦', __('Catalogue')),
    __('Bookkeeping') => $section('business.bookings', '📥', __('Receipts')) + $section('business.report', '📊', __('Annual statement')) + $section('business.settings', '⚙', __('Settings')),
]" />
