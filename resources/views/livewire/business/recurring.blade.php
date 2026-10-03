<?php

use App\Livewire\Concerns\InteractsWithBusiness;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\RecurringInvoice;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithBusiness;
    use InteractsWithHousehold;

    #[Url(as: 'edit', except: 0)]
    public int $editingId = 0;

    /**
     * @var array{name: string, frequency: string, next_run_on: string, ends_on: string, auto_issue: bool, auto_send: bool, active: bool}
     */
    public array $form = [];

    public function mount(): void
    {
        $this->resolveHousehold();

        if ($this->editingId) {
            $this->edit($this->editingId);
        }
    }

    private function templates()
    {
        return RecurringInvoice::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId);
    }

    public function edit(int $templateId): void
    {
        $template = $this->templates()->find($templateId) ?? abort(404);
        $this->resetValidation();
        $this->editingId = $template->id;
        $this->form = [
            'name' => $template->name,
            'frequency' => $template->frequency,
            'next_run_on' => $template->next_run_on->toDateString(),
            'ends_on' => (string) $template->ends_on?->toDateString(),
            'auto_issue' => $template->auto_issue,
            'auto_send' => $template->auto_send,
            'active' => $template->active,
        ];
    }

    public function closeForm(): void
    {
        $this->editingId = 0;
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.frequency' => ['required', Rule::in(array_keys(RecurringInvoice::FREQUENCIES))],
            'form.next_run_on' => ['required', 'date'],
            'form.ends_on' => ['nullable', 'date', 'after_or_equal:form.next_run_on'],
            'form.auto_issue' => ['boolean'],
            'form.auto_send' => ['boolean'],
            'form.active' => ['boolean'],
        ], attributes: ['form.next_run_on' => __('Next invoice on'), 'form.ends_on' => __('Ends on')])['form'];

        $data['ends_on'] = $data['ends_on'] ?: null;
        $data['auto_send'] = $data['auto_send'] && $data['auto_issue'];
        ($this->templates()->find($this->editingId) ?? abort(404))->update($data);
        $this->editingId = 0;
    }

    /**
     * Creates the next invoice right away (e.g. to try the template).
     */
    public function runNow(int $templateId): void
    {
        $template = $this->templates()->find($templateId) ?? abort(404);
        $invoice = $template->run($this->businessProfile() ?? abort(404));

        $this->redirectRoute('business.index', ['document' => $invoice->id], navigate: true);
    }

    public function delete(int $templateId): void
    {
        ($this->templates()->find($templateId) ?? abort(404))->delete();
        $this->editingId = 0;
    }

    public function with(): array
    {
        return [
            'profile' => $this->businessProfile(),
            'templateList' => $this->templates()->with('customer')->withCount('invoices')->orderByDesc('active')->orderBy('next_run_on')->get(),
            'editing' => $this->editingId ? $this->templates()->find($this->editingId) : null,
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $frequencies = ['monthly' => __('monthly'), 'quarterly' => __('quarterly'), 'halfyearly' => __('half-yearly'), 'yearly' => __('yearly')];
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $profile?->company_name ?: __('Small business') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your business data.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('business.partials.nav', ['active' => 'business.recurring'])

            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Recurring invoices are created automatically on the given date – issued and sent by e-mail if you like. Create one from any invoice with “🔁 Repeat …”.') }}</p>

            @if ($templateList->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center shadow-xs dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">🔁</p>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('No recurring invoices yet – open an invoice and choose “Repeat …”, e.g. for maintenance contracts or subscriptions.') }}</p>
                </div>
            @else
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white shadow-xs dark:divide-gray-700 dark:bg-gray-800">
                    @foreach ($templateList as $template)
                        <li wire:key="recurring-{{ $template->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                            <button type="button" wire:click="edit({{ $template->id }})" class="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-1 text-start">
                                <span class="min-w-[10rem] flex-1">
                                    <span @class(['block truncate text-sm font-medium', 'text-gray-900 dark:text-gray-100' => $template->active, 'text-gray-400 line-through' => ! $template->active])>{{ $template->name }}</span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $template->customer?->name ?: strtok($template->recipient, "\n") }} · {{ $frequencies[$template->frequency] }} · {{ trans_choice(':count invoice|:count invoices', $template->invoices_count) }}</span>
                                </span>
                                <span class="text-xs text-gray-600 dark:text-gray-300">
                                    @if ($template->active)
                                        {{ __('next on :date', ['date' => $template->next_run_on->format('d.m.Y')]) }}
                                        @if ($template->auto_send) · ✉ @elseif ($template->auto_issue) · ✓ @else · ✎ @endif
                                    @else
                                        {{ __('paused') }}
                                    @endif
                                </span>
                                <span class="w-24 text-end text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($template->amount()) }}</span>
                            </button>
                            <button type="button" wire:click="runNow({{ $template->id }})" wire:confirm="{{ __('Create the next invoice now?') }}" class="rounded-lg px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-gray-700">▶ {{ __('Create now') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @if ($editing)
        <x-sheet close="closeForm" :label="$editing->name" wire:key="recurring-form-{{ $editing->id }}">
            <x-slot:header><strong class="text-gray-800 dark:text-gray-100">🔁 {{ $editing->name }}</strong></x-slot:header>

            <form id="recurring-form" wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-input-label for="recurring-name" :value="__('Name')" />
                        <x-text-input id="recurring-name" wire:model="form.name" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('form.name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="recurring-frequency" :value="__('Repeat')" />
                        <select id="recurring-frequency" wire:model="form.frequency" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            @foreach ($frequencies as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="recurring-next" :value="__('Next invoice on')" />
                        <x-text-input id="recurring-next" type="date" wire:model="form.next_run_on" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('form.next_run_on')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="recurring-end" :value="__('Ends on (optional)')" />
                        <x-text-input id="recurring-end" type="date" wire:model="form.ends_on" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('form.ends_on')" class="mt-1" />
                    </div>
                </div>
                <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="form.auto_issue" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900"> {{ __('Issue automatically (otherwise a draft is created)') }}</label>
                    <label @class(['flex items-center gap-2', 'opacity-50' => ! ($form['auto_issue'] ?? false)])><input type="checkbox" wire:model="form.auto_send" @disabled(! ($form['auto_issue'] ?? false)) class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900"> {{ __('Send by e-mail to the customer') }}@if (! $editing->customer?->email) <span class="text-xs text-amber-700 dark:text-amber-300">({{ __('the customer has no e-mail address') }})</span>@endif</label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.active" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900"> {{ __('Active') }}</label>
                </div>
                <div class="rounded-xl bg-gray-50 p-3 text-sm dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Items') }}</p>
                    <ul class="mt-1 space-y-1">
                        @foreach ($editing->items as $item)
                            <li class="flex justify-between gap-3"><span class="truncate">{{ rtrim(rtrim(number_format((float) $item['quantity'], 3, ',', ''), '0'), ',') }} × {{ strtok($item['description'], "\n") }}</span><span class="tabular-nums">{{ $euro((float) $item['quantity'] * (float) $item['unit_price'] * (1 - (float) ($item['discount_percent'] ?? 0) / 100)) }}</span></li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('To change the items, edit a created invoice and repeat it again.') }}</p>
                </div>
            </form>

            <x-slot:footer>
                <x-primary-button form="recurring-form">{{ __('Save') }}</x-primary-button>
                <button type="button" wire:click="delete({{ $editing->id }})" wire:confirm="{{ __('Delete this recurring invoice? Invoices already created stay.') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
