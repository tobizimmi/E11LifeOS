{{-- Sections of the admin area (only for administrators). --}}
@php
    $section = fn (string $route, string $icon, string $label) => [$route => [$icon, $label, route($route)]];
    $active = collect(['admin.system', 'admin.households', 'admin.settings', 'admin.audit'])->first(fn (string $route) => request()->routeIs($route)) ?? '';
@endphp
<x-section-nav class="mt-3" :label="__('Administration')" :active="$active" :groups="['' => $section('admin.system', '🩺', __('System')) + $section('admin.households', '🏠', __('Households')) + $section('admin.settings', '🛠', __('Server settings')) + $section('admin.audit', '📜', __('Admin log'))]" />
