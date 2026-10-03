<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\PortfolioSetting;
use App\Models\Security;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\StockScore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    /** Security picker: new tracked security. */
    public string $trackedSymbol = '';

    public string $trackedName = '';

    public string $trackedFilter = '';

    /** New symbol mapping: ISIN or WKN → symbol. */
    public string $mappingKey = '';

    public string $mappingSymbol = '';

    public string $mappingName = '';

    public string $mappingFilter = '';

    /**
     * Weighting per factor of the points system (0 = left out, 1–3).
     *
     * @var array<string, string>
     */
    public array $scoreWeights = [];

    public function mount(): void
    {
        $this->resolveHousehold();
        $settings = $this->portfolioSettings();
        $this->scoreWeights = array_map('strval', StockScore::weights($settings->score_weights));

        $this->form = [
            'default_portfolio_id' => (string) $settings->default_portfolio_id,
            'benchmark_symbol' => $settings->benchmark_symbol ?? '^GDAXI',
            'tax_allowance' => (string) ($settings->tax_allowance ?? 1000),
            'church_tax_rate' => (string) (float) ($settings->church_tax_rate ?? 0),
            'strategy' => $settings->strategy ?? 'moderate',
            'drop_threshold' => (string) ($settings->drop_threshold ?? 10),
            'drop_period_days' => (string) ($settings->drop_period_days ?? 30),
            'rsi_threshold' => (string) ($settings->rsi_threshold ?? 45),
            'min_market_cap_bn' => (string) ($settings->min_market_cap_bn ?? 0),
            'min_stability_score' => (string) ($settings->min_stability_score ?? 40),
            'max_pe_ratio' => (string) ($settings->max_pe_ratio ?? 999),
            'max_debt_equity' => (string) ($settings->max_debt_equity ?? 999),
            'check_200ma' => (bool) $settings->check_200ma,
            'prefer_dividends' => (bool) $settings->prefer_dividends,
            'pushover_user_key' => (string) $settings->pushover_user_key,
            'pushover_app_token' => '',
            'news_push_enabled' => (bool) $settings->news_push_enabled,
            'news_push_portfolio_only' => (bool) ($settings->news_push_portfolio_only ?? true),
            'news_push_max_age_hours' => (string) ($settings->news_push_max_age_hours ?? 24),
            'notifications_enabled' => (bool) ($settings->notifications_enabled ?? true),
        ];
    }

    /**
     * Weighting presets of the book: the original one (double/normal) and the "creative" one with
     * triple weighting for valuation, index, diversification, dividend, earnings and 52-week range.
     */
    public function scorePreset(string $preset): void
    {
        $creative = ['pe' => 3, 'index' => 3, 'diversification' => 3, 'dividend' => 3, 'revenue_earnings' => 3, 'high_low' => 3, 'technical' => 2, 'debt' => 2, 'value' => 2, 'growth' => 2, 'reputation' => 2, 'tradability' => 2, 'book' => 1, 'sustainability' => 1];
        $this->scoreWeights = array_map('strval', StockScore::weights($preset === 'creative' ? $creative : []));
        $this->saveScoreWeights();
    }

    public function saveScoreWeights(): void
    {
        $weights = collect(StockScore::factors())->map(fn (array $factor, string $key) => max(0, min(3, (int) ($this->scoreWeights[$key] ?? $factor['weight']))))->all();
        $settings = $this->portfolioSettings();
        $settings->fill(['score_weights' => $weights === StockScore::weights() ? null : $weights])->save();
        $this->scoreWeights = array_map('strval', $weights);
        $this->dispatch('toast', message: __('Weighting saved.'));
    }

    public function save(): void
    {
        foreach (['tax_allowance', 'drop_threshold', 'rsi_threshold', 'min_market_cap_bn', 'min_stability_score', 'max_pe_ratio', 'max_debt_equity'] as $field) {
            $this->form[$field] = $this->parseNumber($this->form[$field]);
        }

        $data = $this->validate([
            'form.default_portfolio_id' => ['nullable', 'integer'],
            'form.benchmark_symbol' => ['required', 'string', 'max:20'],
            'form.tax_allowance' => ['required', 'numeric', 'min:0', 'max:100000'],
            'form.church_tax_rate' => ['required', 'in:0,0.08,0.09'],
            'form.strategy' => ['required', 'in:'.implode(',', PortfolioSetting::STRATEGIES)],
            'form.drop_threshold' => ['required', 'numeric', 'min:1', 'max:90'],
            'form.drop_period_days' => ['required', 'integer', 'min:5', 'max:365'],
            'form.rsi_threshold' => ['required', 'numeric', 'min:5', 'max:95'],
            'form.min_market_cap_bn' => ['required', 'numeric', 'min:0', 'max:10000'],
            'form.min_stability_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'form.max_pe_ratio' => ['required', 'numeric', 'min:1', 'max:999'],
            'form.max_debt_equity' => ['required', 'numeric', 'min:0', 'max:999'],
            'form.check_200ma' => ['boolean'],
            'form.prefer_dividends' => ['boolean'],
            'form.pushover_user_key' => ['nullable', 'string', 'max:50'],
            'form.pushover_app_token' => ['nullable', 'string', 'max:50'],
            'form.news_push_enabled' => ['boolean'],
            'form.news_push_portfolio_only' => ['boolean'],
            'form.news_push_max_age_hours' => ['required', 'integer', 'in:1,3,6,12,24,48,72,168'],
            'form.notifications_enabled' => ['boolean'],
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

    /**
     * Sends a test message to Pushover with the saved key and token.
     */
    public function testPushover(): void
    {
        $settings = $this->portfolioSettings();
        $token = $settings->pushover_app_token ?: config('services.pushover.token');

        if (! $settings->pushover_user_key || ! $token) {
            $this->addError('pushover', __('Save a Pushover user key (and an app token, unless the server has one) first.'));

            return;
        }

        try {
            $response = Http::asForm()->timeout(10)->post('https://api.pushover.net/1/messages.json', [
                'token' => $token,
                'user' => $settings->pushover_user_key,
                'title' => config('app.name'),
                'message' => __('Test message: Pushover works.'),
            ]);
            $response->successful()
                ? $this->dispatch('toast', message: __('Test message sent.'))
                : $this->addError('pushover', __('Pushover rejected the message: :message', ['message' => implode(' ', (array) $response->json('errors', [$response->status()]))]));
        } catch (\Throwable $exception) {
            $this->addError('pushover', __('Pushover is not reachable: :message', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * Keeps fetching prices and news of a security nobody holds or watches.
     */
    public function addTracked(): void
    {
        $symbol = strtoupper(trim($this->trackedSymbol));
        $this->validate(['trackedSymbol' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9.\-^=]+$/']], attributes: ['trackedSymbol' => __('Symbol')]);

        $security = Security::firstOrNew(['symbol' => $symbol]);
        $security->name = $security->name ?: ($this->trackedName ?: $symbol);
        $security->tracked = true;
        $security->save();

        $this->reset('trackedSymbol', 'trackedName');
    }

    public function removeTracked(string $symbol): void
    {
        Security::where('symbol', $symbol)->update(['tracked' => false]);
    }

    /**
     * Fixed assignment of an ISIN or WKN to a symbol, used by the broker import.
     */
    public function addMapping(): void
    {
        $this->mappingKey = strtoupper(trim($this->mappingKey));
        $this->mappingSymbol = strtoupper(trim($this->mappingSymbol));
        $this->validate([
            'mappingKey' => ['required', function (string $attribute, string $value, \Closure $fail) {
                if (! MarketData::isIsin($value) && ! MarketData::isWkn($value)) {
                    $fail(__('Enter a valid ISIN (12 characters) or WKN (6 characters).'));
                }
            }],
            'mappingSymbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9.\-^=]+$/'],
        ], attributes: ['mappingKey' => __('ISIN / WKN'), 'mappingSymbol' => __('Symbol')]);

        DB::table('security_mappings')->upsert([[
            'input_key' => $this->mappingKey,
            'symbol' => $this->mappingSymbol,
            'data' => json_encode(['symbol' => $this->mappingSymbol, 'name' => $this->mappingName ?: null, 'manual' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['input_key'], ['symbol', 'data', 'updated_at']);

        $security = Security::firstOrNew(['symbol' => $this->mappingSymbol]);
        $security->name = $security->name ?: ($this->mappingName ?: $this->mappingSymbol);
        $security->isin ??= MarketData::isIsin($this->mappingKey) ? $this->mappingKey : null;
        $security->wkn ??= MarketData::isWkn($this->mappingKey) ? $this->mappingKey : null;
        $security->save();

        $this->reset('mappingKey', 'mappingSymbol', 'mappingName');
    }

    public function removeMapping(string $key): void
    {
        DB::table('security_mappings')->where('input_key', $key)->delete();
    }

    public function with(): array
    {
        $settings = $this->portfolioSettings();

        $trackedFilter = '%'.mb_strtolower(trim($this->trackedFilter)).'%';
        $mappingFilter = '%'.mb_strtolower(trim($this->mappingFilter)).'%';

        return [
            'tracked' => Security::where('tracked', true)
                ->where(fn ($query) => $query->whereRaw('LOWER(symbol) LIKE ?', [$trackedFilter])->orWhereRaw('LOWER(name) LIKE ?', [$trackedFilter]))
                ->orderBy('symbol')->limit(50)->get(['symbol', 'name', 'last_price', 'currency']),
            'trackedCount' => Security::where('tracked', true)->count(),
            'mappings' => DB::table('security_mappings')->whereNotNull('symbol')
                ->where(fn ($query) => $query->whereRaw('LOWER(input_key) LIKE ?', [$mappingFilter])->orWhereRaw('LOWER(symbol) LIKE ?', [$mappingFilter]))
                ->orderBy('input_key')->limit(50)->get(),
            'mappingCount' => DB::table('security_mappings')->whereNotNull('symbol')->count(),
            'portfolioList' => $this->portfolios()->orderBy('id')->get(),
            'hasPushoverToken' => filled($settings->pushover_app_token) || filled(config('services.pushover.token')),
            'hasOwnPushoverToken' => filled($settings->pushover_app_token),
            'jobs' => $this->scheduledJobs(),
        ];
    }

    /**
     * The portfolio's automatic jobs (cron jobs of the scheduler) with their next run.
     *
     * @return list<array{label: string, when: string, next: ?\Illuminate\Support\Carbon}>
     */
    private function scheduledJobs(): array
    {
        $labels = [
            'portfolio:update-prices --history' => __('Load price histories'),
            'portfolio:update-prices' => __('Update prices'),
            'portfolio:check-alerts' => __('Check price alerts'),
            'portfolio:execute-savings-plans' => __('Execute savings plans'),
            'portfolio:market-scan' => __('Market scan, key figures and recommendations'),
            'portfolio:fetch-news' => __('Fetch news'),
            'portfolio:instagram' => __('Analyse Instagram posts'),
        ];
        $jobs = [];
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

        // The schedule is only defined when the console starts – in a web request it is read here.
        if ($schedule->events() === []) {
            require base_path('routes/console.php');
        }

        foreach ($schedule->events() as $event) {
            $command = (string) $event->command;

            foreach ($labels as $key => $label) {
                if (str_contains($command, "'artisan' ".$key) && ($key !== 'portfolio:update-prices' || ! str_contains($command, '--history'))) {
                    try {
                        $next = \Illuminate\Support\Carbon::instance($event->nextRunDate(timezone: config('app.timezone')));
                    } catch (Throwable) {
                        $next = null;
                    }

                    $jobs[] = ['label' => $label, 'when' => $event->expression, 'next' => $next];
                    break;
                }
            }
        }

        return $jobs;
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => 'portfolio.settings'])

            <form wire:submit="save" class="space-y-5">
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
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
                        <div>
                            <x-input-label for="settings-church" :value="__('Church tax')" />
                            <select id="settings-church" wire:model="form.church_tax_rate" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                <option value="0">{{ __('none') }}</option>
                                <option value="0.08">{{ __('8 % (Bavaria, Baden-Württemberg)') }}</option>
                                <option value="0.09">{{ __('9 % (other federal states)') }}</option>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
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
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.check_200ma" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ __('Only above the 200-day line') }}</label>
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.prefer_dividends" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ __('Prefer dividend stocks') }}</label>
                        </div>
                        <div>
                            <x-input-label for="settings-stability" :value="__('Minimum stability score (0–100)')" />
                            <x-text-input id="settings-stability" wire:model="form.min_stability_score" inputmode="decimal" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.min_stability_score')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="settings-pe" :value="__('Maximum P/E ratio')" />
                            <x-text-input id="settings-pe" wire:model="form.max_pe_ratio" inputmode="decimal" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.max_pe_ratio')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="settings-de" :value="__('Maximum debt/equity')" />
                            <x-text-input id="settings-de" wire:model="form.max_debt_equity" inputmode="decimal" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.max_debt_equity')" class="mt-1" />
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('The fixed strategies bring their own limits; the values here apply to the strategy “Custom”. 999 means no limit.') }}</p>
                    <details class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
                        <summary class="cursor-pointer font-medium">{{ __('How is the stability score calculated?') }}</summary>
                        <ul class="mt-2 list-disc space-y-1 ps-5 text-xs">
                            <li>{{ __('Up to 20 points each for: market capitalisation, low P/E ratio, low debt compared to equity, revenue growth and operating margin (maximum 100).') }}</li>
                            <li>{{ __('If some key figures are missing, the score is scaled to the available ones.') }}</li>
                            <li>{{ __('Without any key figures a security gets 55 points and must only reach half the minimum.') }}</li>
                        </ul>
                    </details>
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
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
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.news_push_enabled" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ __('Push news about my securities') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.news_push_portfolio_only" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ __('Only securities in my portfolios (not the watchlist)') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.notifications_enabled" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ __('Send notifications via Pushover') }}</label>
                        <div>
                            <x-input-label for="settings-news-age" :value="__('Push only headlines not older than')" />
                            <select id="settings-news-age" wire:model="form.news_push_max_age_hours" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                @foreach ([1, 3, 6, 12, 24, 48, 72, 168] as $hours)
                                    <option value="{{ $hours }}">{{ $hours < 48 ? trans_choice(':count hour|:count hours', $hours) : trans_choice(':count day|:count days', intdiv($hours, 24)) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <x-secondary-button type="button" wire:click="testPushover">🔔 {{ __('Send Pushover test') }}</x-secondary-button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Save first, then test.') }}</span>
                    </div>
                    <x-input-error :messages="$errors->get('pushover')" class="mt-1" />
                </section>

                <x-primary-button>{{ __('Save') }}</x-primary-button>
            </form>

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Tracked securities') }} <span class="text-sm font-normal text-gray-500">({{ $trackedCount }})</span></h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Prices and news of these securities are fetched regularly, even if they are in no portfolio and on no watchlist (e.g. for analyses and recommendations). Applies to all users.') }}</p>
                <div class="mt-4 flex flex-wrap items-start gap-2">
                    <div class="min-w-0 flex-1">@include('portfolio.partials.security-picker', ['id' => 'tracked-search', 'model' => 'trackedSymbol', 'nameModel' => 'trackedName', 'placeholder' => __('Add security: name, symbol, ISIN or WKN')])</div>
                    <x-secondary-button type="button" wire:click="addTracked" :disabled="$trackedSymbol === ''">＋ {{ $trackedSymbol !== '' ? $trackedSymbol : __('Add') }}</x-secondary-button>
                </div>
                <x-input-error :messages="$errors->get('trackedSymbol')" class="mt-1" />
                @if ($trackedCount > 0)
                    <x-text-input type="search" wire:model.live.debounce.300ms="trackedFilter" class="mt-3 block w-full sm:w-64" :placeholder="__('Filter …')" />
                    <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                        @foreach ($tracked as $security)
                            <li wire:key="tracked-{{ $security->symbol }}" class="flex items-center gap-3 py-1.5">
                                <a href="{{ route('portfolio.security', ['symbol' => $security->symbol]) }}" wire:navigate class="w-28 shrink-0 font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $security->symbol }}</a>
                                <span class="min-w-0 flex-1 truncate text-gray-600 dark:text-gray-400">{{ $security->name }}</span>
                                <span class="shrink-0 tabular-nums text-xs text-gray-500">{{ $security->last_price ? number_format($security->last_price, 2, ',', '.').' '.$security->currency : '' }}</span>
                                <button type="button" wire:click="removeTracked(@js($security->symbol))" class="shrink-0 text-xs text-red-600 hover:underline dark:text-red-400">{{ __('remove') }}</button>
                            </li>
                        @endforeach
                    </ul>
                    @if ($trackedCount > $tracked->count())
                        <p class="mt-1 text-xs text-gray-500">{{ __('Showing :shown of :total – use the filter.', ['shown' => $tracked->count(), 'total' => $trackedCount]) }}</p>
                    @endif
                @endif
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Symbol mapping (ISIN / WKN → symbol)') }} <span class="text-sm font-normal text-gray-500">({{ $mappingCount }})</span></h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('The broker import finds the trading symbol for ISIN and WKN automatically. If it picks the wrong exchange or finds nothing, assign it here.') }}</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-[10rem_1fr_auto] sm:items-start">
                    <div>
                        <x-text-input wire:model="mappingKey" class="block w-full" :placeholder="__('ISIN or WKN')" aria-label="{{ __('ISIN / WKN') }}" />
                        <x-input-error :messages="$errors->get('mappingKey')" class="mt-1" />
                    </div>
                    <div>
                        @include('portfolio.partials.security-picker', ['id' => 'mapping-search', 'model' => 'mappingSymbol', 'nameModel' => 'mappingName', 'placeholder' => $mappingSymbol !== '' ? $mappingSymbol.($mappingName !== '' ? ' – '.$mappingName : '') : __('Symbol, e.g. SAP.DE')])
                        <x-input-error :messages="$errors->get('mappingSymbol')" class="mt-1" />
                    </div>
                    <x-secondary-button type="button" wire:click="addMapping">＋ {{ __('Assign') }}</x-secondary-button>
                </div>
                @if ($mappingCount > 0)
                    <x-text-input type="search" wire:model.live.debounce.300ms="mappingFilter" class="mt-3 block w-full sm:w-64" :placeholder="__('Filter …')" />
                    <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                        @foreach ($mappings as $mapping)
                            <li wire:key="mapping-{{ $mapping->input_key }}" class="flex items-center gap-3 py-1.5">
                                <span class="w-32 shrink-0 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $mapping->input_key }}</span>
                                <span class="text-gray-400">→</span>
                                <span class="min-w-0 flex-1 truncate font-medium text-gray-900 dark:text-gray-100">{{ $mapping->symbol }} <span class="font-normal text-gray-500">{{ json_decode((string) $mapping->data, true)['name'] ?? '' }}</span></span>
                                <button type="button" wire:click="removeMapping(@js($mapping->input_key))" class="shrink-0 text-xs text-red-600 hover:underline dark:text-red-400">{{ __('remove') }}</button>
                            </li>
                        @endforeach
                    </ul>
                    @if ($mappingCount > $mappings->count())
                        <p class="mt-1 text-xs text-gray-500">{{ __('Showing :shown of :total – use the filter.', ['shown' => $mappings->count(), 'total' => $mappingCount]) }}</p>
                    @endif
                @endif
            </section>

            <section id="score-weights" class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="score-weights-heading">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 id="score-weights-heading" class="font-semibold text-gray-900 dark:text-gray-100">🏅 {{ __('Weighting of the points system') }}</h3>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <button type="button" wire:click="scorePreset('book')" class="rounded-full px-3 py-1 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">{{ __('As in the book') }}</button>
                        <button type="button" wire:click="scorePreset('creative')" class="rounded-full px-3 py-1 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">{{ __('Creative variant (triple)') }}</button>
                    </div>
                </div>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('How much each factor counts: every point is multiplied by the weighting. “Off” leaves a factor out.') }}</p>
                <form wire:submit="saveScoreWeights" class="mt-3">
                    <div class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                        @foreach (StockScore::factors() as $factorKey => $factor)
                            <label class="flex items-center justify-between gap-3 text-sm" wire:key="weight-{{ $factorKey }}">
                                <span class="min-w-0 text-gray-800 dark:text-gray-200">{{ $factor['label'] }}@unless ($factor['auto']) <span class="text-xs text-gray-500 dark:text-gray-400">· {{ __('own rating') }}</span>@endunless</span>
                                <select wire:model="scoreWeights.{{ $factorKey }}" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                    <option value="0">{{ __('Off') }}</option>
                                    <option value="1">× 1</option>
                                    <option value="2">× 2</option>
                                    <option value="3">× 3</option>
                                </select>
                            </label>
                        @endforeach
                    </div>
                    <x-primary-button class="mt-4">{{ __('Save') }}</x-primary-button>
                </form>
            </section>

            @if ($jobs !== [])
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="portfolio-jobs">
                    <h3 id="portfolio-jobs" class="font-semibold text-gray-900 dark:text-gray-100">⏱ {{ __('Automatic jobs') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('These run on the server by themselves (cron jobs) – nothing to set up. Prices are updated on weekdays during trading hours.') }}</p>
                    <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                        @foreach ($jobs as $job)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span class="text-gray-900 dark:text-gray-100">{{ $job['label'] }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400"><code>{{ $job['when'] }}</code>@if ($job['next']) · {{ __('next: :time', ['time' => $job['next']->format('d.m. H:i')]) }}@endif</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
