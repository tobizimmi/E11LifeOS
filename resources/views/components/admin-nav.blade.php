{{-- Tabs of the admin area (only for administrators). --}}
@php
    $tabs = [
        'admin.system' => ['🩺', __('System')],
        'admin.households' => ['🏠', __('Households')],
        'admin.settings' => ['🛠', __('Server settings')],
        'admin.audit' => ['📜', __('Admin log')],
    ];
@endphp
<nav class="mt-3 -mb-1 flex gap-1 overflow-x-auto" aria-label="{{ __('Administration') }}">
    @foreach ($tabs as $route => [$icon, $label])
        <a href="{{ route($route) }}" wire:navigate @if (request()->routeIs($route)) aria-current="page" @endif
            @class(['shrink-0 rounded-full px-3 py-1.5 text-sm', 'bg-indigo-600 text-white' => request()->routeIs($route), 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => ! request()->routeIs($route)])>{{ $icon }} {{ $label }}</a>
    @endforeach
</nav>
