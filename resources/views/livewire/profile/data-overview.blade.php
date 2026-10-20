<?php

use App\Models\Attachment;
use App\Models\BankLink;
use App\Models\CalendarSubscription;
use App\Models\Collection;
use App\Models\Item;
use App\Models\Portfolio;
use App\Models\PushSubscription;
use App\Models\SmartHomeConnection;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * A transparent overview of the data the member keeps in the tool and which services are connected –
 * so the member can see at a glance what they have and reach the export in one place.
 */
new class extends Component
{
    public function with(): array
    {
        $user = Auth::user();
        $household = $user->currentHousehold();
        $householdId = $household?->id;

        $items = fn (string $type) => $householdId
            ? Item::where('type', $type)->forHousehold($householdId)->visibleTo($user)->count()
            : 0;

        return [
            'counts' => [
                ['📄', __('Pages'), $items(Item::TYPE_NOTE)],
                ['✅', __('To-Do'), $items(Item::TYPE_TODO)],
                ['📥', __('Inbox'), $items(Item::TYPE_INBOX)],
                ['🗂', __('Database entries'), $items(Item::TYPE_ENTRY)],
                ['🧩', __('Databases'), $householdId ? Collection::where('household_id', $householdId)->whereNull('system_key')->count() : 0],
                ['📎', __('Attachments'), Attachment::where('created_by', $user->id)->count()],
                ['💶', __('Bookings'), Transaction::where('user_id', $user->id)->count()],
                ['📈', __('Portfolios'), Portfolio::where('user_id', $user->id)->count()],
            ],
            'integrations' => [
                [__('Document archive (paperless)'), $user->hasPaperless()],
                [__('Bank connections'), BankLink::where('user_id', $user->id)->count()],
                [__('Smart home'), $householdId ? SmartHomeConnection::where('household_id', $householdId)->count() : 0],
                [__('Push devices'), PushSubscription::where('user_id', $user->id)->count()],
                [__('Calendar feeds'), CalendarSubscription::where('user_id', $user->id)->count()],
            ],
        ];
    }
}; ?>

<section class="space-y-5">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Your data at a glance') }}</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Everything you keep here stays yours. Below is what you have and which services are connected – you can export all of it any time.') }}</p>
    </header>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ($counts as [$icon, $label, $value])
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
                <p class="text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($value, 0, ',', '.') }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $icon }} {{ $label }}</p>
            </div>
        @endforeach
    </div>

    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Connected services') }}</h3>
        <ul class="mt-2 flex flex-wrap gap-2 text-xs">
            @foreach ($integrations as [$label, $state])
                @php($active = is_bool($state) ? $state : $state > 0)
                <li @class(['rounded-full px-2.5 py-1', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' => $active, 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' => ! $active])>
                    {{ $active ? '●' : '○' }} {{ $label }}{{ is_int($state) && $state > 0 ? ' ('.$state.')' : '' }}
                </li>
            @endforeach
        </ul>
    </div>
</section>
