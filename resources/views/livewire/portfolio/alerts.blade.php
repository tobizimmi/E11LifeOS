<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\PortfolioPosition;
use App\Models\PriceAlert;
use App\Support\Portfolio\MarketData;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    /**
     * @var array{symbol: string, name: string, condition: string, target_price: string}
     */
    public array $form = ['symbol' => '', 'name' => '', 'condition' => 'below', 'target_price' => ''];

    private function alerts()
    {
        return PriceAlert::where('user_id', $this->currentUser()->id);
    }

    public function saveAlert(): void
    {
        $this->form['target_price'] = $this->parseNumber($this->form['target_price']);
        $this->form['symbol'] = strtoupper(trim((string) $this->form['symbol']));

        $data = $this->validate([
            'form.symbol' => ['required', 'string', 'max:32'],
            'form.name' => ['nullable', 'string', 'max:255'],
            'form.condition' => ['required', 'in:above,below'],
            'form.target_price' => ['required', 'numeric', 'gt:0'],
        ], attributes: ['form.symbol' => __('Security'), 'form.target_price' => __('Price')])['form'];

        PriceAlert::create([
            'user_id' => $this->currentUser()->id,
            'symbol' => $data['symbol'],
            'name' => $data['name'] ?: $data['symbol'],
            'condition' => $data['condition'],
            'target_price' => (float) $data['target_price'],
            'currency' => 'EUR',
            'active' => true,
        ]);

        $this->form = ['symbol' => '', 'name' => '', 'condition' => 'below', 'target_price' => ''];
    }

    public function rearm(int $alertId): void
    {
        ($this->alerts()->find($alertId) ?? abort(404))->update(['triggered_at' => null, 'active' => true]);
    }

    public function deleteAlert(int $alertId): void
    {
        ($this->alerts()->find($alertId) ?? abort(404))->delete();
    }

    public function with(MarketData $market): array
    {
        $alerts = $this->alerts()->orderByRaw('triggered_at IS NOT NULL')->orderBy('symbol')->get();
        $marks = PortfolioPosition::whereIn('portfolio_id', $this->portfolios()->select('id'))
            ->where(fn ($query) => $query->whereNotNull('stop_loss_price')->orWhereNotNull('take_profit_price'))->with('portfolio')->get();
        $quotes = $market->quotes($alerts->pluck('symbol')->merge($marks->pluck('symbol'))->unique()->values()->all());

        return [
            'alerts' => $alerts,
            'marks' => $marks,
            'price' => fn (string $symbol) => isset($quotes[$symbol]) && $quotes[$symbol]->last_price ? $market->toEuro($quotes[$symbol]->last_price, $quotes[$symbol]->currency) : null,
        ];
    }
}; ?>

@php
    $euro = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.').' €';
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => 'portfolio.alerts'])

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('New price alert') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Prices are checked every 15 minutes while the markets are open. You get a push notification – and via Pushover if set up.') }}</p>
                <form wire:submit="saveAlert" class="mt-4 grid gap-3 sm:grid-cols-4 sm:items-start">
                    <div class="sm:col-span-2">
                        @if ($form['symbol'])
                            <p class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900/40"><strong>{{ $form['symbol'] }}</strong> <span class="truncate text-gray-600 dark:text-gray-300">{{ $form['name'] }}</span> <button type="button" wire:click="$set('form.symbol', '')" class="ms-auto text-gray-400 hover:text-gray-700" aria-label="{{ __('Change') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button></p>
                        @else
                            @include('portfolio.partials.security-picker', ['id' => 'alert-search', 'model' => 'form.symbol', 'nameModel' => 'form.name'])
                        @endif
                        <x-input-error :messages="$errors->get('form.symbol')" class="mt-1" />
                    </div>
                    <select wire:model="form.condition" aria-label="{{ __('Condition') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        <option value="below">{{ __('falls below') }}</option>
                        <option value="above">{{ __('rises above') }}</option>
                    </select>
                    <div class="flex gap-2">
                        <div class="flex-1">
                            <x-text-input wire:model="form.target_price" inputmode="decimal" class="block w-full" :placeholder="__('Price in €')" aria-label="{{ __('Price') }}" />
                            <x-input-error :messages="$errors->get('form.target_price')" class="mt-1" />
                        </div>
                        <x-primary-button>{{ __('Add') }}</x-primary-button>
                    </div>
                </form>
            </section>

            <section class="overflow-hidden rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                <h3 class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">{{ __('Price alerts') }}</h3>
                @if ($alerts->isEmpty())
                    <p class="border-t border-gray-100 px-4 py-6 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ __('No price alerts yet.') }}</p>
                @else
                    <ul class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($alerts as $alert)
                            <li wire:key="alert-{{ $alert->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                                <span class="min-w-0 flex-1">
                                    <a href="{{ route('portfolio.security', ['symbol' => $alert->symbol]) }}" wire:navigate class="block truncate font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $alert->name ?: $alert->symbol }}</a>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $alert->condition === 'above' ? __('rises above') : __('falls below') }} {{ number_format($alert->target_price, 2, ',', '.') }} {{ $alert->currency }} · {{ __('now') }} {{ $euro($price($alert->symbol)) }}</span>
                                </span>
                                @if ($alert->triggered_at)
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">✓ {{ __('triggered :date', ['date' => $alert->triggered_at->format('d.m.Y H:i')]) }}</span>
                                    <button type="button" wire:click="rearm({{ $alert->id }})" class="rounded-lg px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-gray-700">↺ {{ __('Re-arm') }}</button>
                                @else
                                    <span class="rounded-full bg-sky-50 px-2 py-0.5 text-xs text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">{{ __('active') }}</span>
                                @endif
                                <button type="button" wire:click="deleteAlert({{ $alert->id }})" class="rounded-sm p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-700" aria-label="{{ __('Delete') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="overflow-hidden rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                <h3 class="px-4 pt-3 font-semibold text-gray-900 dark:text-gray-100">{{ __('Stop loss and target prices of your positions') }}</h3>
                <p class="px-4 pb-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Set them on the overview with ✎ next to a position.') }}</p>
                @if ($marks->isEmpty())
                    <p class="border-t border-gray-100 px-4 py-6 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ __('No marks set.') }}</p>
                @else
                    <ul class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($marks as $position)
                            <li wire:key="mark-{{ $position->id }}" class="flex flex-wrap items-center gap-3 px-4 py-2 text-sm">
                                <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ $position->name ?: $position->symbol }} <span class="text-xs text-gray-500">· {{ $position->portfolio->name }}</span></span>
                                <span class="tabular-nums text-gray-600 dark:text-gray-300">{{ __('now') }} {{ $euro($price($position->symbol)) }}</span>
                                @if ($position->stop_loss_price)
                                    <span class="rounded-sm bg-orange-50 px-1.5 text-xs tabular-nums text-orange-800 dark:bg-orange-900/40 dark:text-orange-200">SL {{ $euro($position->stop_loss_price) }}</span>
                                @endif
                                @if ($position->take_profit_price)
                                    <span class="rounded-sm bg-emerald-50 px-1.5 text-xs tabular-nums text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">TP {{ $euro($position->take_profit_price) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</div>
