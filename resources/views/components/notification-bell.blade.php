@props(['count' => 0])

{{-- Bell with the number of unread notifications; opens the notification center. --}}
<a href="{{ route('notifications.index') }}" wire:navigate {{ $attributes->merge(['class' => 'relative p-2 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700']) }} title="{{ __('Notifications') }}" aria-label="{{ trans_choice('Notifications (:count unread)|Notifications (:count unread)', $count) }}">
    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
    @if ($count > 0)
        <span class="absolute right-0.5 top-0.5 min-w-4 rounded-full bg-rose-500 px-1 text-center text-[10px] font-semibold leading-4 text-white">{{ $count > 99 ? '99+' : $count }}</span>
    @endif
</a>
