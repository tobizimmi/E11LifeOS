{{-- Sections of the admin area (only for administrators). --}}
@php
    $section = fn (string $route, string $icon, string $label, ?int $badge = null) => [$route => [$icon, $label, route($route), $badge ?: null]];
    $active = collect(['admin.overview' => 'admin.overview', 'admin.system' => 'admin.system', 'admin.users' => 'admin.users', 'admin.households' => 'admin.households', 'admin.modules' => 'admin.modules', 'admin.support*' => 'admin.support', 'admin.roadmap' => 'admin.roadmap', 'admin.settings' => 'admin.settings', 'admin.audit' => 'admin.audit'])
        ->first(fn (string $key, string $pattern) => request()->routeIs($pattern)) ?? '';
    $unsolved = \App\Models\SupportTicket::unsolved()->count();
@endphp
<x-section-nav class="mt-3" :label="__('Administration')" :active="$active" :groups="[
    '' => $section('admin.overview', '📊', __('Overview')),
    __('Accounts') => $section('admin.users', '👥', __('Users')) + $section('admin.households', '🏠', __('Households')) + $section('admin.modules', '🧩', __('Modules')),
    __('Support & development') => $section('admin.support', '🎧', __('Support'), $unsolved) + $section('admin.roadmap', '🗺', __('Roadmap')),
    __('Operation') => $section('admin.system', '🩺', __('System')) + $section('admin.settings', '🛠', __('Server settings')) + $section('admin.audit', '📜', __('Admin log')),
]" />
