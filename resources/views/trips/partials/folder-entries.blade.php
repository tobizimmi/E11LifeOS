<ul class="mt-2 space-y-2 text-sm">
    @foreach ($entries as $entry)
        <li class="flex gap-3">
            <span class="w-24 shrink-0 tabular-nums text-gray-500 dark:text-gray-400">{{ $entry['time'] ?: '·' }}</span>
            <span class="min-w-0 flex-1">
                <span class="font-medium">{{ $entry['icon'] }} {{ $entry['title'] }}</span>
                @if ($entry['location'] !== '' || $entry['maps'])
                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                        @if ($entry['maps'])
                            <a href="{{ $entry['maps'] }}" target="_blank" rel="noopener noreferrer" class="underline">📍 {{ $entry['location'] ?: __('Map') }}</a>
                        @else
                            📍 {{ $entry['location'] }}
                        @endif
                    </span>
                @endif
                @if ($entry['notes'] !== '')
                    <span class="mt-0.5 block whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $entry['notes'] }}</span>
                @endif
            </span>
        </li>
    @endforeach
</ul>
