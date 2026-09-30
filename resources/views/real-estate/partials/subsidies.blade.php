{{-- Subsidies: own list and matching programmes (variables from real-estate/show). --}}
<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800" aria-label="{{ __('Subsidies') }}">
            <p class="border-b border-gray-100 px-5 py-3 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">{{ __(':secured secured, :pending applied for or planned', ['secured' => $euro($summary['funding']['grants']), 'pending' => $euro($summary['funding']['pending_grants'])]) }}</p>
            @if ($model->subsidies->isEmpty())
                <p class="p-5 text-sm text-gray-500 dark:text-gray-400">{{ __('No subsidies yet – pick a programme on the right.') }}</p>
            @else
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($model->subsidies as $row)
                        <li wire:key="subsidy-{{ $row->id }}" class="flex flex-wrap items-center gap-3 px-5 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $row->title }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ \App\Models\RealEstateSubsidy::kindLabels()[$row->kind] }}
                                    @if ($row->budgetItem) · {{ $row->budgetItem->title }}@endif
                                    @if ($row->deadline_on) · <span @class(['text-red-700 dark:text-red-400' => $row->deadline_on->lt(today()) && in_array($row->status, ['idea', 'applied'], true)])>{{ __('deadline :date', ['date' => $row->deadline_on->format('d.m.Y')]) }}</span>@endif
                                </p>
                            </div>
                            <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $row->amount > 0 ? $euro($row->amount) : '–' }}</p>
                            <select wire:change="setSubsidyStatus({{ $row->id }}, $event.target.value)" aria-label="{{ __('Status') }}" class="rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                @foreach (\App\Models\RealEstateSubsidy::statusLabels() as $key => $label)
                                    <option value="{{ $key }}" @selected($row->status === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="createTask('subsidy', {{ $row->id }})" class="text-gray-400 hover:text-indigo-600" title="{{ __('Create task') }}" aria-label="{{ __('Create task') }}">✅</button>
                            <button type="button" wire:click="editSubsidy({{ $row->id }})" class="text-gray-400 hover:text-indigo-600" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="h-5 w-5" /></button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <form wire:submit="saveSubsidy" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-3 dark:bg-gray-800">
            <h3 class="font-semibold text-gray-900 sm:col-span-2 lg:col-span-3 dark:text-gray-100">{{ $editingSubsidyId ? __('Edit subsidy') : __('New subsidy') }}</h3>
            <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Programme') }}<input type="text" wire:model="subsidy.title" class="{{ $input }}"><x-input-error :messages="$errors->get('subsidy.title')" /></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Type') }}
                <select wire:model="subsidy.kind" class="{{ $input }}">
                    @foreach (\App\Models\RealEstateSubsidy::kindLabels() as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Amount (€)') }}<input type="number" step="100" min="0" wire:model="subsidy.amount" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Status') }}
                <select wire:model="subsidy.status" class="{{ $input }}">
                    @foreach (\App\Models\RealEstateSubsidy::statusLabels() as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('For budget line') }}
                <select wire:model="subsidy.budget_item_id" class="{{ $input }}">
                    <option value="">{{ __('– whole property –') }}</option>
                    @foreach ($model->budgetItems as $budgetItem)
                        <option value="{{ $budgetItem->id }}">{{ $budgetItem->title }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Application deadline') }}<input type="date" wire:model="subsidy.deadline_on" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Applied on') }}<input type="date" wire:model="subsidy.applied_on" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Approved on') }}<input type="date" wire:model="subsidy.approved_on" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 sm:col-span-2 lg:col-span-3 dark:text-gray-300">{{ __('Notes') }}<input type="text" wire:model="subsidy.notes" class="{{ $input }}"></label>
            <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-3">
                <x-primary-button>{{ $editingSubsidyId ? __('Save') : '+ '.__('Add subsidy') }}</x-primary-button>
                @if ($editingSubsidyId || $subsidy['title'] !== '')
                    <x-secondary-button type="button" wire:click="cancelSubsidy">{{ __('Cancel') }}</x-secondary-button>
                @endif
                @if ($editingSubsidyId)
                    <button type="button" wire:click="deleteSubsidy({{ $editingSubsidyId }})" wire:confirm="{{ __('Delete this subsidy?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                @endif
            </div>
        </form>
    </div>

    <section class="space-y-3 rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="programs-heading">
        <h3 id="programs-heading" class="font-semibold text-gray-900 dark:text-gray-100">💡 {{ __('Programmes that may fit') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Based on the usage and the planned energy measures. Conditions change often – check them before applying. Most programmes must be applied for before the contract is signed.') }}</p>
        @forelse ($suggestions as $key => $program)
            <div wire:key="program-{{ $key }}" class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-900">
                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $program['title'] }}</p>
                <p class="mt-0.5 text-gray-600 dark:text-gray-300">{{ $program['description'] }}</p>
                @if ($program['before_start'])
                    <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">⚠ {{ __('Apply before commissioning') }}</p>
                @endif
                <div class="mt-2 flex gap-3">
                    <button type="button" wire:click="useProgram('{{ $key }}')" class="text-indigo-600 underline dark:text-indigo-400">+ {{ __('Add') }}</button>
                    <a href="{{ $program['url'] }}" target="_blank" rel="noopener noreferrer" class="text-gray-500 underline dark:text-gray-400">{{ __('Details') }} ↗</a>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('All suggestions are already on your list.') }}</p>
        @endforelse
        @if (! $model->budgetItems->contains('energy_measure', true))
            <p class="text-xs text-gray-500 dark:text-gray-400">🌱 {{ __('Mark budget lines as energy measures to see more programmes.') }}</p>
        @endif
    </section>
</div>
