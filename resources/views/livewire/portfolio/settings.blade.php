<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\PortfolioSetting;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    public function mount(): void
    {
        $this->resolveHousehold();
        $settings = $this->portfolioSettings();

        $this->form = [
            'default_portfolio_id' => (string) $settings->default_portfolio_id,
            'benchmark_symbol' => $settings->benchmark_symbol ?? '^GDAXI',
            'tax_allowance' => (string) ($settings->tax_allowance ?? 1000),
            'strategy' => $settings->strategy ?? 'moderate',
            'drop_threshold' => (string) ($settings->drop_threshold ?? 10),
            'drop_period_days' => (string) ($settings->drop_period_days ?? 30),
            'rsi_threshold' => (string) ($settings->rsi_threshold ?? 45),
            'min_market_cap_bn' => (string) ($settings->min_market_cap_bn ?? 0),
            'check_200ma' => (bool) $settings->check_200ma,
            'prefer_dividends' => (bool) $settings->prefer_dividends,
            'pushover_user_key' => (string) $settings->pushover_user_key,
            'pushover_app_token' => '',
            'news_push_enabled' => (bool) $settings->news_push_enabled,
            'news_push_portfolio_only' => (bool) ($settings->news_push_portfolio_only ?? true),
        ];
    }

    public function save(): void
    {
        foreach (['tax_allowance', 'drop_threshold', 'rsi_threshold', 'min_market_cap_bn'] as $field) {
            $this->form[$field] = str_replace(',', '.', trim((string) $this->form[$field]));
        }

        $data = $this->validate([
            'form.default_portfolio_id' => ['nullable', 'integer'],
            'form.benchmark_symbol' => ['required', 'string', 'max:20'],
            'form.tax_allowance' => ['required', 'numeric', 'min:0', 'max:100000'],
            'form.strategy' => ['required', 'in:'.implode(',', PortfolioSetting::STRATEGIES)],
            'form.drop_threshold' => ['required', 'numeric', 'min:1', 'max:90'],
            'form.drop_period_days' => ['required', 'integer', 'min:5', 'max:365'],
            'form.rsi_threshold' => ['required', 'numeric', 'min:5', 'max:95'],
            'form.min_market_cap_bn' => ['required', 'numeric', 'min:0', 'max:10000'],
            'form.check_200ma' => ['boolean'],
            'form.prefer_dividends' => ['boolean'],
            'form.pushover_user_key' => ['nullable', 'string', 'max:50'],
            'form.pushover_app_token' => ['nullable', 'string', 'max:50'],
            'form.news_push_enabled' => ['boolean'],
            'form.news_push_portfolio_only' => ['boolean'],
        ], attributes: ['form.benchmark_symbol' => __('Benchmark'), 'form.tax_allowance' => __('Tax-free allowance')])['form'];

        $settings = $this->portfolioSettings();
        $data['default_portfolio_id'] = $data['default_portfolio_id'] && $this->portfolios()->whereKey($data['default_portfolio_id'])->exists() ? (int) $data['default_portfolio_id'] : null;
        $data['benchmark_symbol'] = strtoupper($data['benchmark_symbol']);
        $data['pushover_user_key'] = $data['pushover_user_key'] ?: null;

        // An empty token field keeps the saved token (it is never shown again).
        if (($data['pushover_app_token'] ?? '') === '') {
            unset($data['pushover_app_token']);
        }

        $settings->fill($data)->save();
        $this->form['pushover_app_token'] = '';
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function removePushoverToken(): void
    {
        $this->portfolioSettings()->fill(['pushover_app_token' => null])->save();
    }

    public function with(): array
    {
        $settings = $this->portfolioSettings();

        return [
            'portfolioList' => $this->portfolios()->orderBy('id')->get(),
            'hasPushoverToken' => filled($settings->pushover_app_token) || filled(config('services.pushover.token')),
            'hasOwnPushoverToken' => filled($settings->pushover_app_token),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">📈 {{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => 'portfolio.settings'])

            <form wire:submit="save" class="space-y-5">
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('General') }}</h3>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label for="settings-default" :value="__('Default portfolio')" />
                            <select id="settings-default" wire:model="form.default_portfolio_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                <option value="">—</option>
                                @foreach ($portfolioList as $portfolio)
                                    <option value="{{ $portfolio->id }}">{{ $portfolio->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="settings-benchmark" :value="__('Benchmark (symbol)')" />
                            <x-text-input id="settings-benchmark" wire:model="form.benchmark_symbol" class="mt-1 block w-full" placeholder="^GDAXI" />
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('e.g. ^GDAXI (DAX), ^GSPC (S&P 500), URTH (MSCI World)') }}</p>
                            <x-input-error :messages="$errors->get('form.benchmark_symbol')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="settings-allowance" :value="__('Tax-free allowance (€)')" />
                            <x-text-input id="settings-allowance" wire:model="form.tax_allowance" inputmode="decimal" class="mt-1 block w-full" />
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Freistellungsauftrag: 1,000 € (2,000 € for couples).') }}</p>
                            <x-input-error :messages="$errors->get('form.tax_allowance')" class="mt-1" />
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Buy recommendations') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Criteria for the recommendations: which price drop, how oversold (RSI) and how large a company must be.') }}</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label for="settings-strategy" :value="__('Strategy')" />
                            <select id="settings-strategy" wire:model="form.strategy" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                @foreach (['conservative' => __('Conservative'), 'moderate' => __('Moderate'), 'aggressive' => __('Aggressive'), 'thieme' => __('Thieme (200-day line)'), 'custom' => __('Custom')] as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="settings-drop" :value="__('Minimum price drop (%)')" />
                            <x-text-input id="settings-drop" wire:model="form.drop_threshold" inputmode="decimal" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.drop_threshold')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="settings-period" :value="__('within (days)')" />
                            <x-text-input id="settings-period" type="number" wire:model="form.drop_period_days" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.drop_period_days')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="settings-rsi" :value="__('RSI below')" />
                            <x-text-input id="settings-rsi" wire:model="form.rsi_threshold" inputmode="decimal" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.rsi_threshold')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="settings-cap" :value="__('Minimum market cap (bn)')" />
                            <x-text-input id="settings-cap" wire:model="form.min_market_cap_bn" inputmode="decimal" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.min_market_cap_bn')" class="mt-1" />
                        </div>
                        <div class="space-y-2 pt-6 text-sm">
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.check_200ma" class="rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ __('Only above the 200-day line') }}</label>
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.prefer_dividends" class="rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ __('Prefer dividend stocks') }}</label>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Notifications') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Price alerts and news arrive as push notification in LifeOS. Additionally they can be sent via Pushover.') }}</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="settings-pushover-user" :value="__('Pushover user key')" />
                            <x-text-input id="settings-pushover-user" wire:model="form.pushover_user_key" class="mt-1 block w-full" autocomplete="off" />
                            <x-input-error :messages="$errors->get('form.pushover_user_key')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="settings-pushover-token" :value="__('Pushover app token')" />
                            <x-text-input id="settings-pushover-token" type="password" wire:model="form.pushover_app_token" class="mt-1 block w-full" autocomplete="new-password" :placeholder="$hasOwnPushoverToken ? '••••••••' : ''" />
                            @if ($hasOwnPushoverToken)
                                <button type="button" wire:click="removePushoverToken" class="mt-1 text-xs text-red-600 underline dark:text-red-400">{{ __('Remove saved token') }}</button>
                            @elseif ($hasPushoverToken)
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('The server token is used.') }}</p>
                            @endif
                        </div>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.news_push_enabled" class="rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ __('Push news about my securities') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.news_push_portfolio_only" class="rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ __('Only securities in my portfolios (not the watchlist)') }}</label>
                    </div>
                </section>

                <x-primary-button>{{ __('Save') }}</x-primary-button>
            </form>
        </div>
    </div>
</div>
