<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-xs font-normal text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 rounded-full px-2 py-0.5 shrink-0']) }} title="{{ __('Only visible to you') }}">
    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" />
    </svg>
    {{ __('Private') }}
</span>
