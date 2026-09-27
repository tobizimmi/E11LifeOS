<?php

use App\Models\Household;
use App\Models\ShoppingListEntry;
use App\Support\ShoppingAisles;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Shopping list for people outside the household (link with a random token, no login):
 * they can tick items off and add new ones.
 */
new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';

    public string $label = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->household();
    }

    private function household(): Household
    {
        $household = strlen($this->token) >= 32 ? Household::where('shopping_share_token', $this->token)->first() : null;

        abort_unless($household, 404);

        return $household;
    }

    public function toggle(int $id): void
    {
        $entry = ShoppingListEntry::where('household_id', $this->household()->id)->find($id) ?? abort(404);
        $entry->update(['checked' => ! $entry->checked]);
    }

    public function add(): void
    {
        $validated = $this->validate(['label' => ['required', 'string', 'max:255']]);

        ShoppingListEntry::create(['household_id' => $this->household()->id, 'label' => trim($validated['label'])]);
        $this->reset('label');
    }

    public function with(): array
    {
        $household = $this->household();
        $entries = ShoppingListEntry::where('household_id', $household->id)->orderBy('label')->get();
        $aisles = ShoppingAisles::all();
        $order = array_flip(array_keys($aisles));

        return [
            'household' => $household,
            'openCount' => $entries->where('checked', false)->count(),
            'groups' => $entries->where('checked', false)
                ->groupBy(fn (ShoppingListEntry $entry) => array_key_exists((string) ($entry->aisle ??= ShoppingAisles::guess($entry->label)), $aisles) ? $entry->aisle : ShoppingAisles::OTHER)
                ->sortBy(fn ($group, string $aisle) => $order[$aisle])
                ->map(fn ($group, string $aisle) => ['label' => $aisles[$aisle]['icon'].' '.$aisles[$aisle]['label'], 'entries' => $group])
                ->when($entries->where('checked', true)->isNotEmpty(), fn ($groups) => $groups->put('done', ['label' => '✓ '.__('Done'), 'entries' => $entries->where('checked', true)])),
        ];
    }
}; ?>

<div wire:poll.15s>
    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">🛒 {{ __('Shopping list of :household', ['household' => $household->name]) }}</h1>
    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count item still open|:count items still open', $openCount) }}</p>

    <form wire:submit="add" class="mt-4 flex gap-2">
        <x-text-input wire:model="label" class="block w-full" type="text" placeholder="{{ __('Item...') }}" aria-label="{{ __('Item') }}" />
        <x-primary-button type="submit">{{ __('Add') }}</x-primary-button>
    </form>
    <x-input-error :messages="$errors->get('label')" class="mt-1" />

    <div class="mt-4 space-y-4">
        @forelse ($groups as $groupKey => $group)
            <div wire:key="shared-group-{{ $groupKey }}">
                <h2 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $group['label'] }}</h2>
                <ul class="space-y-1.5">
                    @foreach ($group['entries'] as $entry)
                        <li wire:key="shared-entry-{{ $entry->id }}">
                            <label class="flex cursor-pointer items-center gap-3 rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                <input type="checkbox" wire:click="toggle({{ $entry->id }})" @checked($entry->checked) class="h-5 w-5 rounded border-gray-300 text-indigo-600">
                                <span @class(['flex-1', 'text-gray-400 line-through' => $entry->checked, 'text-gray-900 dark:text-gray-100' => ! $entry->checked])>{{ $entry->label }}</span>
                                @if ($entry->quantity)
                                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $entry->quantity }}</span>
                                @endif
                            </label>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing to buy right now.') }}</p>
        @endforelse
    </div>
</div>
