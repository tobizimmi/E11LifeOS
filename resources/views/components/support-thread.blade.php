{{-- The course of a support ticket. Requesters only see public replies; agents also see internal notes and changes. --}}
@props(['messages', 'ticket', 'agentView' => false])

<ol class="space-y-3">
    @foreach ($messages as $message)
        @continue(! $agentView && ! $message->isPublic())
        @php($fromRequester = $message->user_id === $ticket->user_id && $message->kind !== 'note')
        <li wire:key="message-{{ $message->id }}">
            @if ($message->kind === 'event')
                <p class="px-1 text-center text-xs text-gray-500 dark:text-gray-400">{{ $message->author?->name ?? __('System') }} · {{ $message->body }} · {{ $message->created_at->format('d.m.Y H:i') }}</p>
            @else
                <div @class([
                    'rounded-2xl p-4 text-sm shadow-xs',
                    'bg-amber-50 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:ring-amber-800' => $message->kind === 'note',
                    'bg-white dark:bg-gray-800' => $message->kind !== 'note' && $fromRequester,
                    'bg-indigo-50 dark:bg-indigo-900/30' => $message->kind !== 'note' && ! $fromRequester,
                ])>
                    <p class="flex flex-wrap items-baseline justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <span class="font-medium text-gray-800 dark:text-gray-200">
                            {{ $message->author?->name ?? __('Deleted account') }}
                            @if ($message->kind === 'note') <span class="text-amber-700 dark:text-amber-300">· 🔒 {{ __('Internal note') }}</span>
                            @elseif (! $fromRequester) <span class="text-indigo-600 dark:text-indigo-300">· {{ __('Support') }}</span>
                            @endif
                        </span>
                        <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('d.m.Y H:i') }}</time>
                    </p>
                    <div class="mt-2 whitespace-pre-line break-words text-gray-900 dark:text-gray-100">{{ $message->body }}</div>
                    @if ($message->attachments)
                        <ul class="mt-3 flex flex-wrap gap-2">
                            @foreach ($message->attachments as $index => $file)
                                <li>
                                    <a href="{{ route('support.file', [$message, $index]) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg bg-white/70 px-2.5 py-1 text-xs text-gray-700 ring-1 ring-gray-200 hover:bg-white dark:bg-gray-900/50 dark:text-gray-200 dark:ring-gray-700">
                                        {{ str_starts_with($file['mime'], 'image/') ? '🖼' : '📎' }} {{ $file['name'] }} <span class="text-gray-400">({{ number_format($file['size'] / 1024, 0, ',', '.') }} KB)</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </li>
    @endforeach
</ol>
