<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-1.5 rounded-lg border border-transparent bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-red-500 active:bg-red-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:opacity-50 dark:focus-visible:ring-offset-gray-800 pointer-coarse:min-h-11']) }}>
    {{ $slot }}
</button>
