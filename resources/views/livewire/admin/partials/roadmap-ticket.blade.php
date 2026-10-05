{{-- One ticket in the roadmap with its milestone picker. --}}
<li wire:key="roadmap-ticket-{{ $ticket->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2">
    <a href="{{ route('admin.support.ticket', $ticket) }}" wire:navigate @class(['min-w-0 flex-1 truncate hover:underline', 'text-gray-400 line-through' => in_array($ticket->status, ['solved', 'closed'], true), 'text-gray-900 dark:text-gray-100' => ! in_array($ticket->status, ['solved', 'closed'], true)])>
        {{ $types[$ticket->type]['icon'] ?? '' }} {{ $ticket->number() }} {{ $ticket->subject }}
    </a>
    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $priorityLabels[$ticket->priority] ?? '' }} · {{ $ticketStatus[$ticket->status] ?? '' }}</span>
    <select wire:change="plan({{ $ticket->id }}, $event.target.value)" class="rounded-md border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Milestone') }}">
        <option value="">{{ __('Backlog') }}</option>
        @foreach ($allMilestones as $option)<option value="{{ $option->id }}" @selected($ticket->roadmap_milestone_id === $option->id)>{{ $option->title }}</option>@endforeach
    </select>
</li>
