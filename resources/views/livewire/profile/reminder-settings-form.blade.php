<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $reminderEmails = false;

    public function mount(): void
    {
        $this->reminderEmails = (bool) Auth::user()->reminder_emails;
    }

    public function updatedReminderEmails(): void
    {
        Auth::user()->update(['reminder_emails' => $this->reminderEmails]);
        $this->dispatch('reminders-saved');
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Reminders') }}
        </h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Every morning at 7:00 you get an e-mail with your tasks that are due today or overdue (assigned to you, or unassigned ones you created).') }}
        </p>
    </header>

    <div class="mt-4 flex items-center gap-4">
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" wire:model.live="reminderEmails" class="rounded border-gray-300 dark:border-gray-700 text-indigo-600" />
            {{ __('Send me daily task reminders by e-mail') }}
        </label>
        <x-action-message on="reminders-saved">{{ __('Saved.') }}</x-action-message>
    </div>
</section>
