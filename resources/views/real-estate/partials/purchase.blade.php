{{-- Purchase details, closing costs and financing (variables from real-estate/show). --}}
@php
    $purchase = $summary['purchase'];
@endphp

<div class="grid gap-5 lg:grid-cols-3">
    <form wire:submit="saveDetails" class="space-y-4 rounded-2xl bg-white p-5 shadow-xs lg:col-span-2 dark:bg-gray-800">
        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Object') }}</h3>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="text-sm text-gray-700 sm:col-span-2 lg:col-span-3 dark:text-gray-300">{{ __('Name') }}
                <input type="text" wire:model="details.name" class="{{ $input }}">
                <x-input-error :messages="$errors->get('details.name')" />
            </label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Street') }}<input type="text" wire:model="details.street" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Postcode') }}<input type="text" wire:model="details.postal_code" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('City') }}<input type="text" wire:model="details.city" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('State') }}
                <select wire:model="details.state" class="{{ $input }}">
                    @foreach (\App\Models\RealEstate::stateNames() as $key => $label)
                        <option value="{{ $key }}">{{ $label }} ({{ number_format(\App\Models\RealEstate::TRANSFER_TAX[$key], 1, ',', '') }} %)</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Status') }}
                <select wire:model="details.status" class="{{ $input }}">
                    @foreach (\App\Models\RealEstate::statusLabels() as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Usage') }}
                <select wire:model.live="details.usage" class="{{ $input }}">
                    @foreach (\App\Models\RealEstate::usageLabels() as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            @if ($details['usage'] === 'mixed')
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Let share (%)') }}<input type="number" min="0" max="100" wire:model="details.rental_share" class="{{ $input }}"></label>
            @endif
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Living area (m²)') }}<input type="number" step="0.1" min="0" wire:model="details.living_area" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Plot (m²)') }}<input type="number" step="1" min="0" wire:model="details.plot_area" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Year of construction') }}<input type="number" min="1000" max="2100" wire:model="details.construction_year" class="{{ $input }}"></label>
        </div>

        <h3 class="pt-2 font-semibold text-gray-900 dark:text-gray-100">{{ __('Purchase') }}</h3>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Purchase price') }} (€)<input type="number" step="1000" min="0" wire:model="details.purchase_price" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Purchase date') }}<input type="date" wire:model="details.purchase_date" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Land share of price (%)') }}<input type="number" step="1" min="0" max="100" wire:model="details.land_share" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Transfer tax (%)') }}<input type="number" step="0.1" min="0" wire:model="details.transfer_tax_rate" placeholder="{{ number_format($model->transferTaxRate(), 1, '.', '') }}" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Notary (%)') }}<input type="number" step="0.1" min="0" wire:model="details.notary_rate" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Land registry (%)') }}<input type="number" step="0.1" min="0" wire:model="details.land_registry_rate" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Broker (%)') }}<input type="number" step="0.01" min="0" wire:model="details.broker_rate" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Equity (€)') }}<input type="number" step="1000" min="0" wire:model="details.equity" class="{{ $input }}"></label>
        </div>

        <h3 class="pt-2 font-semibold text-gray-900 dark:text-gray-100">{{ __('Running costs & tax') }}</h3>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @if ($details['usage'] !== 'owner')
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Cold rent per month (€)') }}<input type="number" step="10" min="0" wire:model="details.monthly_rent" class="{{ $input }}"></label>
            @endif
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Non-recoverable costs per month (€)') }}<input type="number" step="10" min="0" wire:model="details.monthly_costs" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Personal tax rate (%)') }}<input type="number" step="1" min="0" max="60" wire:model="details.tax_rate" class="{{ $input }}"></label>
            <label class="text-sm text-gray-700 sm:col-span-2 lg:col-span-3 dark:text-gray-300">{{ __('Notes') }}<textarea wire:model="details.notes" rows="3" class="{{ $input }}"></textarea></label>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-primary-button>{{ __('Save') }}</x-primary-button>
            @if ($model->created_by === auth()->id())
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400"><input type="checkbox" wire:model="details.private" class="rounded-sm border-gray-300 text-indigo-600"> 🔒 {{ __('Only for me') }}</label>
            @endif
            <button type="button" wire:click="deleteRealEstate" wire:confirm="{{ __('Delete this property with all invoices, loans and subsidies?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete property') }}</button>
        </div>
    </form>

    <div class="space-y-5">
        <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="closing-heading">
            <h3 id="closing-heading" class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Purchase costs') }}</h3>
            <dl class="mt-2 space-y-1 text-sm">
                @foreach ([
                    __('Purchase price') => $purchase['price'],
                    __('Transfer tax (:rate %)', ['rate' => number_format($model->transferTaxRate(), 1, ',', '')]) => $purchase['transfer_tax'],
                    __('Notary') => $purchase['notary'],
                    __('Land registry') => $purchase['land_registry'],
                    __('Broker') => $purchase['broker'],
                ] as $label => $value)
                    <div class="flex justify-between gap-2"><dt class="text-gray-600 dark:text-gray-300">{{ $label }}</dt><dd class="text-gray-900 dark:text-gray-100">{{ $euro($value) }}</dd></div>
                @endforeach
                <div class="flex justify-between gap-2 border-t border-gray-100 pt-1 font-semibold dark:border-gray-700"><dt>{{ __('Total') }}</dt><dd>{{ $euro($purchase['total']) }}</dd></div>
            </dl>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Closing costs: :amount (:percent % of the price)', ['amount' => $euro($purchase['closing']), 'percent' => $purchase['price'] > 0 ? number_format($purchase['closing'] / $purchase['price'] * 100, 1, ',', '') : '0']) }}</p>
        </section>

        <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="loans-heading">
            <h3 id="loans-heading" class="font-semibold text-gray-900 dark:text-gray-100">🏦 {{ __('Financing') }}</h3>
            <ul class="mt-2 space-y-2 text-sm">
                @forelse ($model->loans as $loan)
                    @php
                        $atEnd = $loan->after($loan->fixed_years * 12);
                    @endphp
                    <li wire:key="loan-{{ $loan->id }}" class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $loan->lender }} · {{ $euro($loan->amount) }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ \App\Models\RealEstateLoan::kindLabels()[$loan->kind] }} · {{ number_format($loan->interest_rate, 2, ',', '') }} % {{ __('interest') }} · {{ number_format($loan->repayment_rate, 2, ',', '') }} % {{ __('repayment') }}</p>
                            </div>
                            <button type="button" wire:click="deleteLoan({{ $loan->id }})" wire:confirm="{{ __('Delete this loan?') }}" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                        </div>
                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ __(':rate per month', ['rate' => $euro($loan->monthlyRate(), 2)]) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('After :years years: :balance remaining, :interest interest paid', ['years' => $loan->fixed_years, 'balance' => $euro($atEnd['balance']), 'interest' => $euro($atEnd['interest'])]) }}</p>
                        <button type="button" wire:click="$set('scheduleLoanId', {{ $scheduleLoanId === $loan->id ? 'null' : $loan->id }})" class="mt-1 text-xs text-indigo-600 underline dark:text-indigo-400">{{ $scheduleLoanId === $loan->id ? __('Hide repayment plan') : __('Repayment plan') }}</button>
                        @if ($scheduleLoanId === $loan->id && $schedule !== [])
                            <div class="mt-2 max-h-64 overflow-y-auto">
                                <table class="w-full text-xs">
                                    <thead class="text-gray-500 dark:text-gray-400"><tr><th class="text-start">{{ __('Year') }}</th><th class="text-end">{{ __('Interest') }}</th><th class="text-end">{{ __('Repayment') }}</th><th class="text-end">{{ __('Remaining') }}</th></tr></thead>
                                    <tbody>
                                        @foreach ($schedule as $row)
                                            <tr @class(['font-medium' => $row['year'] === $loan->fixed_years])><td>{{ $row['year'] }}</td><td class="text-end">{{ $euro($row['interest']) }}</td><td class="text-end">{{ $euro($row['repayment']) }}</td><td class="text-end">{{ $euro($row['balance']) }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="text-gray-500 dark:text-gray-400">{{ __('No loans yet.') }}</li>
                @endforelse
            </ul>

            <form wire:submit="addLoan" class="mt-3 grid grid-cols-2 gap-2 border-t border-gray-100 pt-3 text-sm dark:border-gray-700">
                <label class="col-span-2 text-gray-700 dark:text-gray-300">{{ __('Lender') }}<input type="text" wire:model="loan.lender" class="{{ $input }}"><x-input-error :messages="$errors->get('loan.lender')" /></label>
                <label class="text-gray-700 dark:text-gray-300">{{ __('Type') }}
                    <select wire:model="loan.kind" class="{{ $input }}">
                        @foreach (\App\Models\RealEstateLoan::kindLabels() as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-gray-700 dark:text-gray-300">{{ __('Loan amount (€)') }}<input type="number" step="1000" min="0" wire:model="loan.amount" class="{{ $input }}"><x-input-error :messages="$errors->get('loan.amount')" /></label>
                <label class="text-gray-700 dark:text-gray-300">{{ __('Interest (%)') }}<input type="number" step="0.01" min="0" wire:model="loan.interest_rate" class="{{ $input }}"></label>
                <label class="text-gray-700 dark:text-gray-300">{{ __('Repayment (%)') }}<input type="number" step="0.1" min="0" wire:model="loan.repayment_rate" class="{{ $input }}"></label>
                <label class="text-gray-700 dark:text-gray-300">{{ __('Fixed rate (years)') }}<input type="number" min="1" max="40" wire:model="loan.fixed_years" class="{{ $input }}"></label>
                <label class="text-gray-700 dark:text-gray-300">{{ __('Start') }}<input type="date" wire:model="loan.start_on" class="{{ $input }}"></label>
                <div class="col-span-2"><x-primary-button>+ {{ __('Add loan') }}</x-primary-button></div>
            </form>
        </section>
    </div>
</div>
