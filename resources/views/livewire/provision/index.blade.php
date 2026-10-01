<?php

use App\Models\Item;
use App\Support\ContractOverview;
use App\Support\Finance\InsuranceCheck;
use App\Support\Finance\SubscriptionMatcher;
use App\Support\Finance\RetirementPlan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Provision check: gaps, duplicates and often unnecessary items among the insurances, and the pension
 * gap with the path to financial freedom.
 */
new #[Layout('layouts.app')] class extends Component
{
    /** insurance or retirement */
    #[Url(except: 'insurance')]
    public string $tab = 'insurance';

    /**
     * @var array<string, string>
     */
    public array $plan = [];

    /** Kind of insurance whose form is open (a key of InsuranceCheck::types()). */
    public ?string $creating = null;

    /**
     * @var array{provider: string, monthly: string, payment: string}
     */
    public array $insurance = ['provider' => '', 'monthly' => '', 'payment' => ''];

    public function mount(): void
    {
        if (! in_array($this->tab, ['insurance', 'retirement'], true)) {
            $this->tab = 'insurance';
        }

        $this->plan = array_map(fn ($value) => (string) $value, RetirementPlan::inputs(Auth::user()));
    }

    public function answer(string $type, ?string $answer): void
    {
        abort_unless(isset(InsuranceCheck::types()[$type]) && in_array($answer, [InsuranceCheck::HAVE, InsuranceCheck::NOT_NEEDED, null], true), 404);

        $user = Auth::user();
        $answers = is_array($user->insurance_answers) ? $user->insurance_answers : [];

        if ($answer === null) {
            unset($answers[$type]);
        } else {
            $answers[$type] = $answer;
        }

        $user->update(['insurance_answers' => $answers ?: null]);
    }

    private function householdId(): int
    {
        return Auth::user()->currentHousehold()?->id ?? abort(404);
    }

    private function findContract(int $itemId): Item
    {
        return collect(ContractOverview::for(Auth::user(), $this->householdId())['contracts'])
            ->first(fn (array $contract) => $contract['item']->id === $itemId)['item'] ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(string $key): array
    {
        return collect(SubscriptionMatcher::for(Auth::user(), $this->householdId())['unmatched'])->firstWhere('key', $key)
            ?? collect(SubscriptionMatcher::payees(Auth::user(), $this->householdId(), PHP_INT_MAX))->firstWhere('key', $key)
            ?? abort(404);
    }

    public function startCreate(string $type): void
    {
        abort_unless(isset(InsuranceCheck::types()[$type]), 404);
        $this->creating = $type;
        $this->insurance = ['provider' => '', 'monthly' => '', 'payment' => ''];
        $this->resetValidation();
    }

    /**
     * Adds the insurance to the contracts; with a payment from the bank bookings it is linked to it.
     */
    public function saveInsurance(): void
    {
        $this->insurance['monthly'] = str_replace(',', '.', trim($this->insurance['monthly']));
        $data = $this->validate([
            'creating' => ['required', Rule::in(array_keys(InsuranceCheck::types()))],
            'insurance.provider' => ['nullable', 'string', 'max:120'],
            'insurance.monthly' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'insurance.payment' => ['nullable', 'string', 'max:255'],
        ], attributes: ['insurance.monthly' => __('Premium per month')]);

        $payment = $data['insurance']['payment'] ? $this->payment($data['insurance']['payment']) : null;
        // A payment seen only once (e.g. a yearly premium) gives no monthly amount – then it stays as entered.
        $monthly = $data['insurance']['monthly'] !== '' && $data['insurance']['monthly'] !== null ? (float) $data['insurance']['monthly'] : (($payment['frequency'] ?? null) ? $payment['monthly'] : null);
        $item = InsuranceCheck::create(Auth::user(), $this->householdId(), (string) $this->creating, trim((string) $data['insurance']['provider']), $monthly, $payment['key'] ?? null);

        $this->creating = null;
        $this->dispatch('toast', message: __('":name" added to your contracts.', ['name' => $item->title]));
    }

    /**
     * Adds an insurance straight from a recurring payment of the bank bookings.
     */
    public function createFromPayment(string $key, string $type): void
    {
        abort_unless(isset(InsuranceCheck::types()[$type]), 404);
        $payment = $this->payment($key);
        $item = InsuranceCheck::create(Auth::user(), $this->householdId(), $type, (string) $payment['name'], (float) $payment['monthly'], $payment['key']);
        $this->dispatch('toast', message: __('":name" added to your contracts.', ['name' => $item->title]));
    }

    /**
     * Sets the kind of insurance of a contract by hand ("none": it is no insurance, "": guess again).
     */
    public function assignContract(int $itemId, string $type): void
    {
        abort_unless($type === '' || $type === InsuranceCheck::NONE || isset(InsuranceCheck::types()[$type]), 404);
        InsuranceCheck::assign($this->findContract($itemId), $type === '' ? null : $type);
    }

    /**
     * Links a recurring payment from the bank bookings to an insurance contract.
     */
    public function linkPayment(int $itemId, string $key): void
    {
        $payment = $this->payment($key);
        $contract = $this->findContract($itemId);
        SubscriptionMatcher::link($contract, $payment['key']);
        $this->dispatch('toast', message: __('Payment linked with ":name".', ['name' => $contract->title]));
    }

    public function unlinkPayment(int $itemId, string $key): void
    {
        SubscriptionMatcher::unlink($this->findContract($itemId), $key);
    }

    /**
     * Hides a recurring payment that is no contract.
     */
    public function dismissPayment(string $key): void
    {
        $this->payment($key);
        $user = Auth::user();
        $user->update(['dismissed_subscriptions' => array_values(array_unique([...$user->dismissed_subscriptions ?? [], $key]))]);
    }

    public function updatedPlan(): void
    {
        $this->plan = array_map(fn ($value) => str_replace(',', '.', trim((string) $value)), $this->plan);

        $data = $this->validate([
            'plan.birth_year' => ['required', 'integer', 'between:1920,'.now()->year],
            'plan.retirement_age' => ['required', 'integer', 'between:40,80'],
            'plan.desired_income' => ['required', 'numeric', 'min:0', 'max:100000'],
            'plan.statutory_pension' => ['required', 'numeric', 'min:0', 'max:100000'],
            'plan.other_pension' => ['required', 'numeric', 'min:0', 'max:100000'],
            'plan.assets' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'plan.monthly_savings' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'plan.return' => ['required', 'numeric', 'between:-5,15'],
            'plan.inflation' => ['required', 'numeric', 'between:0,10'],
            'plan.withdrawal' => ['required', 'numeric', 'between:2,8'],
        ])['plan'];

        Auth::user()->update(['retirement_plan' => $data]);
    }

    public function resetPlan(): void
    {
        Auth::user()->update(['retirement_plan' => null]);
        $this->resetValidation();
        $this->plan = array_map(fn ($value) => (string) $value, RetirementPlan::inputs(Auth::user()->fresh()));
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private static function looksLikeInsurance(array $payment): bool
    {
        return $payment['category'] === 'insurance' || (bool) preg_match('/versicher|insurance|allianz|huk|ergo|axa|debeka|signal iduna|r\+v|generali|zurich|gothaer|lvm|provinzial|devk|cosmos|wwk|hdi|barmenia|hanse ?merkur|nürnberger|continentale|alte leipziger/i', (string) $payment['name']);
    }

    /**
     * The debit shown at each contract: the matched regular payment, otherwise the payee linked by hand
     * (also one paid only once so far, e.g. a yearly premium).
     *
     * @param  ?array{matched: list<array<string, mixed>>}  $matching
     * @param  list<array<string, mixed>>  $payees
     * @return array<int, array<string, mixed>>
     */
    private function linkedPayments(?array $matching, array $payees): array
    {
        $linked = collect($matching['matched'] ?? [])->mapWithKeys(fn (array $match) => [$match['contract']['item']->id => $match['payment']])->all();

        if ($matching === null) {
            return $linked;
        }

        $byKey = collect($payees)->keyBy('key');

        foreach (ContractOverview::for(Auth::user(), $this->householdId())['contracts'] as $contract) {
            if (isset($linked[$contract['item']->id])) {
                continue;
            }

            foreach (SubscriptionMatcher::linkedKeys($contract['item']) as $key) {
                if ($payee = $byKey->get($key)) {
                    $linked[$contract['item']->id] = $payee;
                    break;
                }
            }
        }

        return $linked;
    }

    public function with(): array
    {
        $user = Auth::user();

        $household = $user->currentHousehold();
        $matching = $this->tab === 'insurance' && $household ? SubscriptionMatcher::for($user, $household->id) : null;
        // Insurance payments first: those are the ones that belong here.
        $unmatched = collect($matching['unmatched'] ?? [])->sortBy(fn (array $payment) => self::looksLikeInsurance($payment) ? 0 : 1)->values();
        $payees = $matching ? SubscriptionMatcher::payees($user, $household->id) : [];

        return [
            'check' => $this->tab === 'insurance' ? InsuranceCheck::for($user) : null,
            'unmatchedPayments' => $unmatched,
            'insurancePayments' => $unmatched->filter(fn (array $payment) => self::looksLikeInsurance($payment))->values(),
            'linkedPayments' => $this->linkedPayments($matching, $payees),
            'otherPayments' => collect($payees)->reject(fn (array $payee) => $unmatched->contains('key', $payee['key']))->values(),
            'answers' => is_array($user->insurance_answers) ? $user->insurance_answers : [],
            'result' => $this->tab === 'retirement' ? RetirementPlan::calculate(RetirementPlan::inputs($user)) : null,
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $euro = fn (float $value) => number_format($value, 0, ',', '.').' €';
    $tabClass = 'flex-1 rounded-lg px-3 py-2 text-sm font-medium transition pointer-coarse:min-h-11';
    $types = InsuranceCheck::types();
    $levels = ['essential' => __('Essential'), 'recommended' => __('Recommended'), 'optional' => __('Optional'), 'none' => __('Not needed')];
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Provision check') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <nav class="flex gap-1 rounded-xl bg-gray-100 p-1 dark:bg-gray-800" aria-label="{{ __('Sections') }}">
                @foreach (['insurance' => '🛡️ '.__('Insurances'), 'retirement' => '🌅 '.__('Pension & freedom')] as $key => $label)
                    <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([$tabClass, 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100' => $tab === $key, 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100' => $tab !== $key]) aria-pressed="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </nav>

            @if ($tab === 'insurance')
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Important gaps') }}</p>
                            <p @class(['mt-1 text-3xl font-semibold tabular-nums', 'text-emerald-700 dark:text-emerald-400' => $check['gaps'] === 0, 'text-rose-700 dark:text-rose-400' => $check['gaps'] > 0])>{{ $check['gaps'] }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Insurances per month') }}</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($check['monthly'], 2, ',', '.') }} €</p>
                        </div>
                    </div>
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('Recognised from your contracts (collections with a cost field). Insurance you have elsewhere can be ticked off below.') }}</p>
                </section>

                <ul class="space-y-3">
                    @foreach ($check['rows'] as $row)
                        <li wire:key="insurance-{{ $row['key'] }}" class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $row['icon'] }} {{ $row['label'] }} <span class="ms-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-normal text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $levels[$row['level']] }}</span></p>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $row['why'] }}</p>
                                </div>
                                @switch($row['status'])
                                    @case('ok')
                                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">✓ {{ __('present') }}</span>
                                        @break
                                    @case('duplicate')
                                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">⚠ {{ __('twice – check for overlap') }}</span>
                                        @break
                                    @case('dismissed')
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('not needed') }}</span>
                                        @break
                                    @default
                                        <span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-rose-50 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200' => $row['level'] === 'essential', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $row['level'] !== 'essential'])>{{ __('not found') }}</span>
                                @endswitch
                            </div>
                            @if ($row['contracts'] !== [])
                                <ul class="mt-2 space-y-2 text-sm">
                                    @foreach ($row['contracts'] as $contract)
                                        @php($linked = $linkedPayments[$contract['item_id']] ?? null)
                                        <li wire:key="insurance-contract-{{ $contract['item_id'] }}" class="rounded-xl bg-gray-50 px-3 py-2 dark:bg-gray-900/40">
                                            <div class="flex justify-between gap-3"><a href="{{ $contract['url'] }}" wire:navigate class="min-w-0 text-gray-800 hover:underline dark:text-gray-100">{{ $contract['title'] }}{{ $contract['provider'] && ! str_contains($contract['title'], $contract['provider']) ? ' · '.$contract['provider'] : '' }}</a><span class="tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($contract['monthly'], 2, ',', '.') }} €</span></div>
                                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                                @if ($linked)
                                                    <span class="text-emerald-700 dark:text-emerald-300">🔗 {{ ($linked['frequency'] ?? null) ? __('Debit: :name, :amount € per month', ['name' => $linked['name'], 'amount' => number_format($linked['monthly'], 2, ',', '.')]) : __('Debit: :name, :amount € on :date', ['name' => $linked['name'], 'amount' => number_format($linked['amount'], 2, ',', '.'), 'date' => $linked['lastDate']->format('d.m.Y')]) }}</span>
                                                    @if (in_array($linked['key'], $contract['payments'], true))
                                                        <button type="button" wire:click="unlinkPayment({{ $contract['item_id'] }}, @js($linked['key']))" class="text-gray-500 hover:underline dark:text-gray-400">{{ __('Remove link') }}</button>
                                                    @endif
                                                @elseif ($unmatchedPayments->isNotEmpty() || $otherPayments->isNotEmpty())
                                                    <select x-on:change="$event.target.value && $wire.linkPayment({{ $contract['item_id'] }}, $event.target.value)" class="max-w-full rounded-md border-gray-300 py-1 ps-2 pe-8 text-xs dark:border-gray-700" aria-label="{{ __('Link a payment from the bank bookings') }}">
                                                        <option value="">🔗 {{ __('Link a payment from the bank bookings …') }}</option>
                                                        @include('provision.partials.payment-options')
                                                    </select>
                                                @endif
                                                <select x-on:change="$wire.assignContract({{ $contract['item_id'] }}, $event.target.value)" class="ms-auto rounded-md border-gray-300 py-1 ps-2 pe-8 text-xs dark:border-gray-700" aria-label="{{ __('Kind of insurance') }}">
                                                    <option value="{{ $row['key'] }}" selected>{{ $contract['assigned'] ? __('Kind set by you') : __('Recognised automatically') }}</option>
                                                    @foreach ($types as $typeKey => $type)
                                                        @continue($typeKey === $row['key'])
                                                        <option value="{{ $typeKey }}">→ {{ $type['label'] }}</option>
                                                    @endforeach
                                                    <option value="none">✕ {{ __('No insurance – wrongly recognised') }}</option>
                                                    @if ($contract['assigned'])
                                                        <option value="">↺ {{ __('Recognise automatically again') }}</option>
                                                    @endif
                                                </select>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif ($row['status'] !== 'ok')
                                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                    @if (($answers[$row['key']] ?? null) === null)
                                        <button type="button" wire:click="answer('{{ $row['key'] }}', 'have')" class="rounded-full px-3 py-1 ring-1 ring-gray-200 hover:bg-gray-50 dark:ring-gray-600 dark:hover:bg-gray-700 pointer-coarse:min-h-11">{{ __('I have it elsewhere') }}</button>
                                        <button type="button" wire:click="answer('{{ $row['key'] }}', 'not_needed')" class="rounded-full px-3 py-1 ring-1 ring-gray-200 hover:bg-gray-50 dark:ring-gray-600 dark:hover:bg-gray-700 pointer-coarse:min-h-11">{{ __('Not needed for me') }}</button>
                                    @else
                                        <button type="button" wire:click="answer('{{ $row['key'] }}', null)" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ __('Undo') }}</button>
                                    @endif
                                </div>
                            @elseif (($answers[$row['key']] ?? null) !== null)
                                <button type="button" wire:click="answer('{{ $row['key'] }}', null)" class="mt-2 text-xs text-indigo-600 hover:underline dark:text-indigo-400">{{ __('Undo') }}</button>
                            @endif

                            @if ($creating === $row['key'])
                                <form wire:submit="saveInsurance" class="mt-3 grid gap-2 rounded-xl bg-indigo-50/60 p-3 text-sm sm:grid-cols-3 dark:bg-indigo-900/20">
                                    <label class="text-gray-700 dark:text-gray-300">{{ __('Insurer') }}<input type="text" wire:model="insurance.provider" class="{{ $input }}" placeholder="{{ __('e.g. Allianz, HUK, Debeka') }}" autofocus></label>
                                    <label class="text-gray-700 dark:text-gray-300">{{ __('Premium per month') }}<input type="text" inputmode="decimal" wire:model="insurance.monthly" class="{{ $input }}" placeholder="{{ __('from the payment') }}"><x-input-error :messages="$errors->get('insurance.monthly')" /></label>
                                    <label class="text-gray-700 dark:text-gray-300">{{ __('Payment from Finances') }}
                                        <select wire:model="insurance.payment" class="{{ $input }}">
                                            <option value="">{{ $unmatchedPayments->isEmpty() && $otherPayments->isEmpty() ? __('No payments in Finances') : '–' }}</option>
                                            @include('provision.partials.payment-options')
                                        </select>
                                    </label>
                                    <div class="flex flex-wrap gap-2 sm:col-span-3">
                                        <x-primary-button>{{ __('Add insurance') }}</x-primary-button>
                                        <x-secondary-button type="button" wire:click="$set('creating', null)">{{ __('Cancel') }}</x-secondary-button>
                                    </div>
                                </form>
                            @else
                                <button type="button" wire:click="startCreate('{{ $row['key'] }}')" class="mt-2 text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">+ {{ $row['contracts'] === [] ? __('Add this insurance') : __('Add another') }}</button>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($insurancePayments->isNotEmpty())
                    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="insurance-payments">
                        <h3 id="insurance-payments" class="font-semibold text-gray-900 dark:text-gray-100">💳 {{ __('Insurance payments without a contract') }}</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Recurring debits from your bank bookings that look like an insurance. Add them as insurance, link them to one, or reject them.') }}</p>
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($insurancePayments as $payment)
                                <li wire:key="insurance-payment-{{ md5($payment['key']) }}" class="flex flex-wrap items-center gap-2 py-2 text-sm">
                                    <span class="min-w-0 flex-1"><span class="font-medium text-gray-900 dark:text-gray-100">{{ $payment['name'] }}</span> <span class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($payment['monthly'], 2, ',', '.') }} € / {{ __('month') }}</span></span>
                                    <select x-on:change="$event.target.value && $wire.createFromPayment(@js($payment['key']), $event.target.value)" class="rounded-md border-gray-300 py-1 ps-2 pe-8 text-xs dark:border-gray-700" aria-label="{{ __('Add as insurance') }}">
                                        <option value="">+ {{ __('Add as …') }}</option>
                                        @foreach ($types as $typeKey => $type)
                                            <option value="{{ $typeKey }}">{{ $type['icon'] }} {{ $type['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @php($insuranceContracts = collect($check['found'])->flatten(1)->merge($check['candidates'])->unique('item_id'))
                                    @if ($insuranceContracts->isNotEmpty())
                                        <select x-on:change="$event.target.value && $wire.linkPayment($event.target.value, @js($payment['key']))" class="rounded-md border-gray-300 py-1 ps-2 pe-8 text-xs dark:border-gray-700" aria-label="{{ __('Link to an insurance') }}">
                                            <option value="">🔗 {{ __('Link to …') }}</option>
                                            @foreach ($insuranceContracts as $contract)
                                                <option value="{{ $contract['item_id'] }}">{{ $contract['title'] }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <button type="button" wire:click="dismissPayment(@js($payment['key']))" class="rounded-md px-2 py-1 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">✕ {{ __('Not an insurance') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($check['candidates'] !== [])
                    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="insurance-candidates">
                        <h3 id="insurance-candidates" class="font-semibold text-gray-900 dark:text-gray-100">❔ {{ __('Insurances not recognised') }}</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('These contracts look like an insurance, but the check could not tell which one. Say what it is – or reject it.') }}</p>
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($check['candidates'] as $contract)
                                <li wire:key="insurance-candidate-{{ $contract['item_id'] }}" class="flex flex-wrap items-center gap-2 py-2 text-sm">
                                    <a href="{{ $contract['url'] }}" wire:navigate class="min-w-0 flex-1 font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $contract['title'] }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ number_format($contract['monthly'], 2, ',', '.') }} €</span></a>
                                    <select x-on:change="$event.target.value && $wire.assignContract({{ $contract['item_id'] }}, $event.target.value)" class="rounded-md border-gray-300 py-1 ps-2 pe-8 text-xs dark:border-gray-700" aria-label="{{ __('Kind of insurance') }}">
                                        <option value="">{{ __('This is a …') }}</option>
                                        @foreach ($types as $typeKey => $type)
                                            <option value="{{ $typeKey }}">{{ $type['icon'] }} {{ $type['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="assignContract({{ $contract['item_id'] }}, 'none')" class="rounded-md px-2 py-1 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">✕ {{ __('No insurance') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($check['unnecessary'] !== [])
                    <section class="rounded-2xl bg-amber-50 p-5 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:ring-amber-800">
                        <h3 class="font-semibold text-amber-900 dark:text-amber-200">🤔 {{ __('Often unnecessary') }}</h3>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($check['unnecessary'] as $item)
                                <li class="flex justify-between gap-3"><a href="{{ $item['url'] }}" wire:navigate class="text-gray-900 hover:underline dark:text-gray-100">{{ $item['title'] }} <span class="text-gray-500 dark:text-gray-400">({{ $item['label'] }})</span></a><span class="tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($item['monthly'], 2, ',', '.') }} €</span></li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-amber-900 dark:text-amber-200">{{ __('Consumer advice centres consider these rarely worth it – the money is often better saved.') }}</p>
                    </section>
                @endif
            @else
                <section class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Pension gap per month') }}</p>
                        <p @class(['mt-1 text-3xl font-semibold tabular-nums', 'text-emerald-700 dark:text-emerald-400' => $result['gap'] <= 0, 'text-rose-700 dark:text-rose-400' => $result['gap'] > 0])>{{ $euro($result['gap']) }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('in today’s money') }}</p>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Gap covered at :age', ['age' => (int) $plan['retirement_age']]) }}</p>
                        <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ min(100, $result['coverage']) }} %</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __(':projected of :needed capital', ['projected' => $euro($result['capital_projected']), 'needed' => $euro($result['capital_needed'])]) }}</p>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Financially free at') }}</p>
                        <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $result['freedom_age'] !== null ? __(':age years', ['age' => $result['freedom_age']]) : '–' }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('without any pension, needs :capital', ['capital' => $euro($result['freedom_capital'])]) }}</p>
                    </div>
                </section>

                @if ($result['savings_needed'] > (float) $plan['monthly_savings'])
                    <p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">💡 {{ __('To close the pension gap by :age you would need to save about :amount per month (currently :current).', ['age' => (int) $plan['retirement_age'], 'amount' => $euro($result['savings_needed']), 'current' => $euro((float) $plan['monthly_savings'])]) }}</p>
                @elseif ($result['gap'] > 0)
                    <p class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100">✅ {{ __('With your current savings rate the pension gap is closed by retirement.') }}</p>
                @endif

                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Projected capital (today’s money) and the capital for financial freedom') }}</p>
                    <x-line-chart :points="$result['points']" :value-label="__('Capital')" :compare-label="__('Needed for freedom')" height="200" />
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">⚙️ {{ __('Your inputs') }}</h3>
                        <button type="button" wire:click="resetPlan" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">{{ __('Take values from my data') }}</button>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-5">
                        @foreach ([
                            'birth_year' => __('Year of birth'),
                            'retirement_age' => __('Retirement age'),
                            'desired_income' => __('Desired net income per month (€)'),
                            'statutory_pension' => __('Statutory pension per month (€)'),
                            'other_pension' => __('Company / private pensions (€)'),
                            'assets' => __('Invested assets today (€)'),
                            'monthly_savings' => __('Savings per month (€)'),
                            'return' => __('Expected return (% p.a.)'),
                            'inflation' => __('Inflation (% p.a.)'),
                            'withdrawal' => __('Withdrawal rate (%)'),
                        ] as $key => $label)
                            <label class="text-sm text-gray-700 dark:text-gray-300">{{ $label }}<input type="text" inputmode="decimal" wire:model.blur="plan.{{ $key }}" class="{{ $input }}"><x-input-error :messages="$errors->get('plan.'.$key)" /></label>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('The statutory pension is on your yearly pension information letter. All amounts in today’s money; the return is reduced by inflation (real :rate %). 4 % rule: 25 times the yearly withdrawal.', ['rate' => number_format($result['real_return'], 2, ',', '.')]) }}</p>
                </section>
            @endif

            <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans('finance-tips.disclaimer', [], 'de') }}</p>
        </div>
    </div>
</div>
