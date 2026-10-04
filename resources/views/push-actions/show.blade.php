<x-guest-layout>
    <div class="space-y-4 text-center">
        <p class="text-3xl" aria-hidden="true">❓</p>
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Your answer') }}</h1>

        @if (session('status'))
            <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200" role="status">{{ session('status') }}</p>
        @elseif (! $action->isOpen())
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('This question has already been answered or has expired.') }}</p>
        @else
            <form method="POST" action="{{ route('push-actions.answer', $token) }}" class="flex flex-col gap-2 sm:flex-row sm:justify-center">
                @csrf
                @foreach ($choices as $choice)
                    <button type="submit" name="pick" value="{{ $choice->id }}"
                        @class(['rounded-lg px-4 py-2 text-sm font-medium', 'bg-indigo-600 text-white' => ($choice->payload['choice'] ?? '') === 'yes', 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100' => ($choice->payload['choice'] ?? '') !== 'yes'])>{{ $choice->label }}</button>
                @endforeach
            </form>
        @endif
    </div>
</x-guest-layout>
