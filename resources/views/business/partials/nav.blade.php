{{-- Tabs of the small business section ($active: route name of the current tab). --}}
<nav class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="{{ __('Small business') }}">
    <div class="inline-flex min-w-max rounded-lg bg-white p-1 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        @foreach ([
            'business.index' => '🧾 '.__('Invoices'),
            'business.bookings' => '📥 '.__('Receipts'),
            'business.customers' => '👥 '.__('Customers'),
            'business.report' => '📊 '.__('Annual statement'),
            'business.settings' => '⚙ '.__('Settings'),
        ] as $routeName => $label)
            <a href="{{ route($routeName) }}" wire:navigate @class(['rounded-md px-3 py-1.5 text-sm whitespace-nowrap', 'bg-indigo-600 text-white' => $active === $routeName, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $active !== $routeName]) aria-current="{{ $active === $routeName ? 'page' : 'false' }}">{{ $label }}</a>
        @endforeach
    </div>
</nav>
