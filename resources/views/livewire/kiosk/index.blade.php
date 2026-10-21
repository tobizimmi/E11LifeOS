<?php

use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Support\CalendarEvents;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Family / kiosk dashboard: a large, glanceable full-screen view for a wall tablet in the kitchen –
 * the day's agenda and the open shopping list, refreshing itself so the whole household stays in sync.
 */
new #[Layout('layouts.wall')] class extends Component
{
    public function with(): array
    {
        $user = Auth::user();
        $household = $user->currentHousehold();

        if (! $household) {
            return ['agenda' => [], 'shopping' => collect(), 'today' => today()];
        }

        $today = today();
        $agenda = CalendarEvents::between(
            $user,
            $household->id,
            $today->copy()->startOfDay(),
            $today->copy()->endOfDay(),
            [CalendarEvents::TASKS, CalendarEvents::DATES, CalendarEvents::MEALS, CalendarEvents::LIFE, CalendarEvents::EXTERNAL],
        );

        $shopping = ShoppingListEntry::where('household_id', $household->id)
            ->where('checked', false)
            ->whereIn('shopping_list_id', ShoppingList::where('household_id', $household->id)->select('id'))
            ->orderBy('aisle')->orderBy('position')->orderBy('id')
            ->limit(60)->get();

        return ['agenda' => $agenda, 'shopping' => $shopping, 'today' => $today];
    }
}; ?>

<div wire:poll.visible.60s class="min-h-screen bg-gray-950 p-6 text-gray-100 sm:p-8" x-data="{ now: '' }"
     x-init="now = new Date().toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }); setInterval(() => now = new Date().toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }), 10000)">

    <header class="flex flex-wrap items-end justify-between gap-4 border-b border-white/10 pb-5">
        <div>
            <p class="text-lg font-medium text-indigo-300 sm:text-2xl">{{ $today->translatedFormat('l') }}</p>
            <p class="text-sm text-gray-400 sm:text-lg">{{ $today->translatedFormat('j. F Y') }}</p>
        </div>
        <p class="font-semibold tabular-nums text-white" style="font-size: clamp(2.75rem, 10vw, 6rem); line-height: 1" x-text="now">–</p>
    </header>

    <div class="mt-6 grid gap-6 lg:grid-cols-5">
        {{-- Agenda --}}
        <section class="lg:col-span-3">
            <h2 class="mb-4 text-xl font-semibold text-gray-200 sm:text-2xl">📅 {{ __('Today') }}</h2>
            @forelse ($agenda as $event)
                <div wire:key="ev-{{ $loop->index }}" class="mb-2 flex items-baseline gap-3 rounded-2xl bg-white/5 px-4 py-3 sm:gap-4 sm:px-5 sm:py-4">
                    <span class="w-16 shrink-0 text-right text-lg font-semibold tabular-nums text-indigo-300 sm:w-20 sm:text-2xl">{{ $event['time'] ?? '–' }}</span>
                    <span @class(['text-lg sm:text-2xl', 'text-gray-100' => ! ($event['overdue'] ?? false), 'text-rose-300' => $event['overdue'] ?? false])>{{ $event['title'] }}</span>
                </div>
            @empty
                <p class="rounded-2xl bg-white/5 px-5 py-8 text-center text-lg text-gray-400 sm:text-2xl">{{ __('Nothing scheduled today. Enjoy the day!') }}</p>
            @endforelse
        </section>

        {{-- Shopping list --}}
        <section class="lg:col-span-2">
            <h2 class="mb-4 text-xl font-semibold text-gray-200 sm:text-2xl">🛒 {{ __('Shopping List') }}</h2>
            <div class="rounded-2xl bg-white/5 p-4 sm:p-5">
                @forelse ($shopping as $entry)
                    <div wire:key="sh-{{ $entry->id }}" class="flex items-baseline gap-3 py-1.5 text-lg sm:text-xl">
                        <span class="text-gray-500">○</span>
                        <span class="text-gray-100">{{ $entry->label }}</span>
                        @if ($entry->quantity)<span class="text-gray-400">· {{ $entry->quantity }}</span>@endif
                    </div>
                @empty
                    <p class="py-6 text-center text-lg text-gray-400 sm:text-xl">{{ __('The shopping list is empty.') }}</p>
                @endforelse
            </div>
        </section>
    </div>

    <footer class="mt-8 flex items-center justify-between text-sm text-gray-500">
        <span>{{ __('Updates automatically.') }}</span>
        <a href="{{ route('dashboard') }}" wire:navigate class="rounded-lg bg-white/10 px-3 py-1.5 text-gray-300 hover:bg-white/20">{{ __('Exit') }}</a>
    </footer>
</div>
