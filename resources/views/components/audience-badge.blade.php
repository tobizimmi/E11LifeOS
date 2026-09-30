@props(['model', 'quiet' => false])

@php
    // Who can see this content: only me, my household, groups beyond the household – or it
    // comes from another household and was shared with me.
    $audience = $model->audience(auth()->user()?->currentHousehold()?->id);
@endphp

@if ($audience['level'] === 'foreign')
    <span {{ $attributes->merge(['class' => 'inline-flex max-w-full items-center gap-1 truncate rounded-full bg-violet-50 px-2 py-0.5 text-xs font-normal text-violet-800 ring-1 ring-violet-200 dark:bg-violet-900/30 dark:text-violet-200 dark:ring-violet-800']) }} title="{{ __('From another household, shared via: :groups', ['groups' => implode(', ', $audience['groups'])]) }}">
        ↪ {{ __('Shared by :name', ['name' => $audience['sharer'] ?? __('someone')]) }}
    </span>
@elseif ($audience['level'] === 'groups')
    <span {{ $attributes->merge(['class' => 'inline-flex max-w-full items-center gap-1 truncate rounded-full bg-sky-50 px-2 py-0.5 text-xs font-normal text-sky-800 ring-1 ring-sky-200 dark:bg-sky-900/30 dark:text-sky-200 dark:ring-sky-800']) }} title="{{ __('Also visible to people outside your household') }}">
        👥 {{ $audience['household'] ? __('Household').' + ' : '' }}{{ implode(', ', $audience['groups']) }}
    </span>
@elseif ($audience['level'] === 'household' && ! $quiet)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-normal text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200']) }} title="{{ __('Visible to your household') }}">🏠 {{ __('Household') }}</span>
@elseif ($audience['level'] === 'private')
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-normal text-amber-800 dark:bg-amber-900/30 dark:text-amber-200']) }} title="{{ __('Only visible to you') }}">🔒 {{ __('Only me') }}</span>
@endif
