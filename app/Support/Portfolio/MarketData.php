<?php

namespace App\Support\Portfolio;

use App\Models\Security;
use App\Models\SecurityPrice;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Quotes, price history, search and ISIN/WKN resolution. Yahoo Finance (no key) is the main
 * source, Twelve Data (optional key) the preferred source for daily history, OpenFIGI resolves
 * ISIN/WKN to symbols. Everything is stored in the securities tables, so pages read from the
 * database and the scheduler keeps it fresh.
 */
class MarketData
{
    private const CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    private const SEARCH_URL = 'https://query1.finance.yahoo.com/v1/finance/search';

    private const TWELVE_DATA_URL = 'https://api.twelvedata.com/time_series';

    private const OPENFIGI_URL = 'https://api.openfigi.com/v3/mapping';

    private const SUMMARY_URL = 'https://query2.finance.yahoo.com/v10/finance/quoteSummary/';

    private const SCREENER_URL = 'https://query1.finance.yahoo.com/v1/finance/screener/predefined/saved';

    /** Yahoo screeners used by the market scan. */
    public const SCREENERS = ['day_losers', 'undervalued_large_caps', 'undervalued_growth_stocks', 'growth_technology_stocks', 'most_actives', 'day_gainers'];

    /** Market overview on the discover page. */
    public const INDICES = ['^GDAXI' => 'DAX', '^MDAXI' => 'MDAX', '^STOXX50E' => 'Euro Stoxx 50', '^GSPC' => 'S&P 500', '^NDX' => 'Nasdaq 100', '^N225' => 'Nikkei 225', 'GC=F' => 'Gold', 'BTC-EUR' => 'Bitcoin', 'EURUSD=X' => 'EUR/USD'];

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36';

    /** Quotes younger than this are served from the database. */
    public const QUOTE_TTL_MINUTES = 15;

    /** OpenFIGI exchange code → Yahoo suffix (German exchanges first). */
    private const EXCHANGE_SUFFIXES = [
        'GY' => '.DE', 'GF' => '.F', 'GS' => '.SG', 'GM' => '.MU', 'GH' => '.HM', 'GD' => '.DU', 'GB' => '.BE',
        'LN' => '.L', 'FP' => '.PA', 'NA' => '.AS', 'IM' => '.MI', 'SW' => '.SW', 'AV' => '.VI', 'BB' => '.BR',
        'CN' => '.TO', 'AU' => '.AX', 'HK' => '.HK', 'JP' => '.T',
        'US' => '', 'UN' => '', 'UW' => '', 'UA' => '', 'UR' => '', 'UV' => '',
    ];

    /** Yahoo suffix → Twelve Data exchange. */
    private const TWELVE_DATA_EXCHANGES = [
        '.DE' => 'XETRA', '.F' => 'FWB', '.SG' => 'XSTU', '.MU' => 'XMUN', '.HM' => 'XHAM', '.DU' => 'XDUS', '.BE' => 'XBER',
        '.L' => 'LSE', '.PA' => 'XPAR', '.AS' => 'XAMS', '.MI' => 'XMIL', '.SW' => 'SWX', '.VI' => 'XWBO', '.BR' => 'XBRU',
        '.TO' => 'TSX', '.AX' => 'ASX', '.HK' => 'HKEX', '.T' => 'TSE',
    ];

    public static function isIsin(string $value): bool
    {
        return (bool) preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', strtoupper(trim($value)));
    }

    public static function isWkn(string $value): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{6}$/', strtoupper(trim($value))) && preg_match('/\d/', $value);
    }

    // ── Quotes ───────────────────────────────────────────────────────────

    /**
     * Current quotes (in the security's currency); stale or missing ones are fetched in parallel.
     *
     * @param  list<string>  $symbols
     * @return array<string, Security>
     */
    public function quotes(array $symbols, bool $refresh = false): array
    {
        $symbols = array_values(array_unique(array_filter(array_map(fn ($symbol) => strtoupper(trim((string) $symbol)), $symbols))));

        if ($symbols === []) {
            return [];
        }

        $known = Security::whereIn('symbol', $symbols)->get()->keyBy('symbol');
        $stale = array_values(array_filter($symbols, fn (string $symbol) => $refresh
            || ! $known->has($symbol)
            || ! $known[$symbol]->price_updated_at
            || $known[$symbol]->price_updated_at->lt(now()->subMinutes(self::QUOTE_TTL_MINUTES))));

        foreach (array_chunk($stale, 20) as $chunk) {
            foreach ($this->fetchQuotes($chunk) as $symbol => $quote) {
                $known[$symbol] = $this->storeQuote($symbol, $quote);
            }
        }

        return collect($symbols)->filter(fn (string $symbol) => $known->has($symbol))->mapWithKeys(fn (string $symbol) => [$symbol => $known[$symbol]])->all();
    }

    public function quote(string $symbol, bool $refresh = false): ?Security
    {
        return $this->quotes([$symbol], $refresh)[strtoupper(trim($symbol))] ?? null;
    }

    /**
     * Exchange rate, e.g. fxRate('USD', 'EUR') ≈ 0.92.
     */
    public function fxRate(string $from, string $to = 'EUR'): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to || $from === '') {
            return 1.0;
        }

        return (float) Cache::remember("portfolio:fx:{$from}{$to}", now()->addHour(), function () use ($from, $to) {
            $quote = $this->quote($from.$to.'=X');

            return $quote?->last_price > 0 ? $quote->last_price : 1.0;
        });
    }

    /**
     * Price converted to euros (London quotes in pence are divided by 100).
     */
    public function toEuro(float $price, ?string $currency): float
    {
        if ($currency === 'GBp' || $currency === 'GBX') {
            return $price / 100 * $this->fxRate('GBP');
        }

        return $price * $this->fxRate((string) ($currency ?: 'EUR'));
    }

    /**
     * @param  list<string>  $symbols
     * @return array<string, array<string, mixed>>
     */
    private function fetchQuotes(array $symbols): array
    {
        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $symbol) => $pool->as($symbol)->withHeaders(['User-Agent' => self::USER_AGENT])->timeout(8)
                    ->get(self::CHART_URL.rawurlencode($symbol), ['range' => '5d', 'interval' => '1d']),
                $symbols,
            ));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $quotes = [];

        foreach ($responses as $symbol => $response) {
            if ($response instanceof Response && $response->successful()) {
                $quote = $this->parseChart((string) $symbol, (array) $response->json());

                if ($quote !== null) {
                    $quotes[(string) $symbol] = $quote;
                }
            }
        }

        return $quotes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function parseChart(string $symbol, array $data): ?array
    {
        $result = $data['chart']['result'][0] ?? null;
        $meta = $result['meta'] ?? null;
        $price = (float) ($meta['regularMarketPrice'] ?? 0);

        if (! $meta || $price <= 0) {
            return null;
        }

        // The previous close is the second-to-last daily close (chartPreviousClose is the one before the range).
        $closes = array_values(array_filter($result['indicators']['quote'][0]['close'] ?? [], fn ($close) => $close !== null));
        $previous = count($closes) >= 2 ? (float) $closes[count($closes) - 2] : (float) ($meta['previousClose'] ?? $meta['chartPreviousClose'] ?? 0);

        return [
            'name' => $meta['longName'] ?? $meta['shortName'] ?? null,
            'currency' => $meta['currency'] ?? null,
            'exchange' => $meta['fullExchangeName'] ?? $meta['exchangeName'] ?? null,
            'asset_type' => $this->assetType((string) ($meta['instrumentType'] ?? '')),
            'price' => round($price, 4),
            'previous_close' => $previous > 0 ? round($previous, 4) : null,
            'week_52_high' => isset($meta['fiftyTwoWeekHigh']) ? round((float) $meta['fiftyTwoWeekHigh'], 4) : null,
            'week_52_low' => isset($meta['fiftyTwoWeekLow']) ? round((float) $meta['fiftyTwoWeekLow'], 4) : null,
            'history' => $this->parseHistory($result),
        ];
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    private function storeQuote(string $symbol, array $quote): Security
    {
        $security = Security::firstOrNew(['symbol' => $symbol]);
        $security->fill(array_filter([
            'name' => $security->name ?: $quote['name'],
            'currency' => $quote['currency'],
            'exchange' => $security->exchange ?: $quote['exchange'],
            'asset_type' => $security->exists ? null : $quote['asset_type'],
            'last_price' => $quote['price'],
            'previous_close' => $quote['previous_close'],
            'week_52_high' => $quote['week_52_high'],
            'week_52_low' => $quote['week_52_low'],
        ], fn ($value) => $value !== null && $value !== ''))->forceFill(['price_updated_at' => now()]);
        $security->name ??= $symbol;
        $security->save();

        $this->storeHistory($symbol, $quote['history']);

        return $security;
    }

    private function assetType(string $instrumentType): string
    {
        return match (strtoupper($instrumentType)) {
            'ETF' => 'etf',
            'MUTUALFUND' => 'fund',
            'CRYPTOCURRENCY' => 'crypto',
            'EQUITY' => 'stock',
            '' => 'stock',
            default => 'other',
        };
    }

    // ── History ──────────────────────────────────────────────────────────

    /**
     * Daily closes since $from; fetched when missing or older than yesterday.
     *
     * @return Collection<int, SecurityPrice>
     */
    public function history(string $symbol, Carbon $from): Collection
    {
        $symbol = strtoupper($symbol);
        $query = fn () => SecurityPrice::where('symbol', $symbol)->where('date', '>=', $from->toDateString())->orderBy('date')->get();
        $prices = $query();
        $oldest = SecurityPrice::where('symbol', $symbol)->min('date');
        $newest = SecurityPrice::where('symbol', $symbol)->max('date');
        $lastTradingDay = today()->isMonday() ? today()->subDays(3) : (today()->isWeekend() ? today()->previous(Carbon::FRIDAY) : today()->subDay());

        $missingStart = ! $oldest || Carbon::parse($oldest)->gt($from->copy()->addDays(7));
        $missingEnd = ! $newest || Carbon::parse($newest)->lt($lastTradingDay);

        if (($missingStart || $missingEnd) && Cache::add("portfolio:history-fetch:{$symbol}:".$from->toDateString(), true, now()->addHour())) {
            $this->storeHistory($symbol, $this->fetchHistory($symbol, $from));
            $prices = $query();
        }

        return $prices;
    }

    /**
     * @return list<array{date: string, open: ?float, high: ?float, low: ?float, close: float, volume: ?int}>
     */
    private function fetchHistory(string $symbol, Carbon $from): array
    {
        $days = max(5, (int) $from->diffInDays(today()) + 5);

        if (filled(config('services.twelve_data.key')) && ! str_starts_with($symbol, '^') && ! str_contains($symbol, '=')) {
            try {
                $response = Http::timeout(10)->get(self::TWELVE_DATA_URL, [
                    'symbol' => $this->twelveDataSymbol($symbol),
                    'interval' => '1day',
                    'outputsize' => min(5000, $days),
                    'order' => 'ASC',
                    'apikey' => config('services.twelve_data.key'),
                ]);
                $values = $response->json('values');

                if ($response->successful() && is_array($values) && $values !== []) {
                    return array_values(array_filter(array_map(fn (array $row) => isset($row['close'], $row['datetime']) ? [
                        'date' => substr((string) $row['datetime'], 0, 10),
                        'open' => isset($row['open']) ? (float) $row['open'] : null,
                        'high' => isset($row['high']) ? (float) $row['high'] : null,
                        'low' => isset($row['low']) ? (float) $row['low'] : null,
                        'close' => (float) $row['close'],
                        'volume' => isset($row['volume']) ? (int) $row['volume'] : null,
                    ] : null, $values)));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $range = match (true) {
            $days <= 7 => '5d',
            $days <= 31 => '1mo',
            $days <= 93 => '3mo',
            $days <= 186 => '6mo',
            $days <= 366 => '1y',
            $days <= 731 => '2y',
            $days <= 1830 => '5y',
            $days <= 3660 => '10y',
            default => 'max',
        };

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(15)
                ->get(self::CHART_URL.rawurlencode($symbol), ['range' => $range, 'interval' => '1d']);

            return $response->successful() ? $this->parseHistory($response->json('chart.result.0') ?? []) : [];
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array{date: string, open: ?float, high: ?float, low: ?float, close: float, volume: ?int}>
     */
    private function parseHistory(array $result): array
    {
        $quote = $result['indicators']['quote'][0] ?? [];
        $rows = [];

        foreach ($result['timestamp'] ?? [] as $index => $timestamp) {
            $close = $quote['close'][$index] ?? null;

            if ($close === null) {
                continue;
            }

            $rows[] = [
                'date' => Carbon::createFromTimestamp((int) $timestamp, 'Europe/Berlin')->toDateString(),
                'open' => isset($quote['open'][$index]) ? round((float) $quote['open'][$index], 4) : null,
                'high' => isset($quote['high'][$index]) ? round((float) $quote['high'][$index], 4) : null,
                'low' => isset($quote['low'][$index]) ? round((float) $quote['low'][$index], 4) : null,
                'close' => round((float) $close, 4),
                'volume' => isset($quote['volume'][$index]) ? (int) $quote['volume'][$index] : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{date: string, open: ?float, high: ?float, low: ?float, close: float, volume: ?int}>  $rows
     */
    private function storeHistory(string $symbol, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('security_prices')->upsert(
                array_map(fn (array $row) => ['symbol' => $symbol] + $row, $chunk),
                ['symbol', 'date'],
                ['open', 'high', 'low', 'close', 'volume'],
            );
        }
    }

    private function twelveDataSymbol(string $symbol): string
    {
        foreach (self::TWELVE_DATA_EXCHANGES as $suffix => $exchange) {
            if (str_ends_with($symbol, $suffix)) {
                return substr($symbol, 0, -strlen($suffix)).':'.$exchange;
            }
        }

        return $symbol;
    }

    // ── Search & resolution ──────────────────────────────────────────────

    /**
     * Securities matching a name, symbol, ISIN or WKN (known ones first, then Yahoo search).
     *
     * @return list<array{symbol: string, name: string, exchange: string, type: string}>
     */
    public function search(string $query, int $limit = 8): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $upper = strtoupper($query);
        $results = collect();

        if (self::isIsin($upper) || self::isWkn($upper)) {
            $resolved = $this->resolve($upper);

            if ($resolved) {
                $results->push(['symbol' => $resolved->symbol, 'name' => $resolved->name, 'exchange' => (string) $resolved->exchange, 'type' => $resolved->asset_type]);
            }
        }

        $results = $results->concat(Security::where('symbol', 'like', $upper.'%')->orWhere('name', 'like', '%'.$query.'%')->orWhere('isin', $upper)
            ->orderByRaw('CASE WHEN symbol = ? THEN 0 WHEN symbol LIKE ? THEN 1 ELSE 2 END', [$upper, $upper.'%'])
            ->limit($limit)->get()
            ->map(fn (Security $security) => ['symbol' => $security->symbol, 'name' => $security->name, 'exchange' => (string) $security->exchange, 'type' => $security->asset_type]));

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(4)
                ->get(self::SEARCH_URL, ['q' => $query, 'quotesCount' => $limit, 'newsCount' => 0, 'lang' => 'de-DE']);

            foreach ((array) $response->json('quotes') as $quote) {
                if (empty($quote['symbol']) || ! in_array($quote['quoteType'] ?? '', ['EQUITY', 'ETF', 'MUTUALFUND', 'CRYPTOCURRENCY', 'INDEX'], true)) {
                    continue;
                }

                $results->push([
                    'symbol' => strtoupper((string) $quote['symbol']),
                    'name' => (string) ($quote['longname'] ?? $quote['shortname'] ?? $quote['symbol']),
                    'exchange' => (string) ($quote['exchDisp'] ?? $quote['exchange'] ?? ''),
                    'type' => $this->assetType((string) ($quote['quoteType'] ?? '')),
                ]);
            }
        } catch (Throwable) {
            // Offline: the known securities are enough.
        }

        return $results->unique('symbol')->take($limit)->values()->all();
    }

    /**
     * Symbol for an ISIN or WKN (cached; OpenFIGI, then Yahoo search, German exchanges preferred).
     */
    public function resolve(string $isinOrWkn): ?Security
    {
        return $this->resolveMany([$isinOrWkn])[strtoupper(trim($isinOrWkn))] ?? null;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, Security>
     */
    public function resolveMany(array $keys): array
    {
        $keys = array_values(array_unique(array_filter(array_map(fn ($key) => strtoupper(trim((string) $key)), $keys), fn (string $key) => self::isIsin($key) || self::isWkn($key))));
        $resolved = [];

        if ($keys === []) {
            return [];
        }

        $known = Security::whereIn('isin', $keys)->orWhereIn('wkn', $keys)->get();
        $cached = DB::table('security_mappings')->whereIn('input_key', $keys)->get()->keyBy('input_key');
        $open = [];

        foreach ($keys as $key) {
            $security = $known->first(fn (Security $security) => $security->isin === $key || $security->wkn === $key);

            if (! $security && isset($cached[$key]) && $cached[$key]->symbol) {
                $security = Security::firstWhere('symbol', $cached[$key]->symbol);
            }

            if ($security) {
                $resolved[$key] = $security;
            } elseif (! isset($cached[$key]) || Carbon::parse($cached[$key]->updated_at)->lt(now()->subWeek())) {
                $open[] = $key;
            }
        }

        // OpenFIGI takes 10 jobs per request without key, 100 with.
        foreach (array_chunk($open, filled(config('services.openfigi.key')) ? 100 : 10) as $chunk) {
            $figi = $this->openFigi($chunk);

            foreach ($chunk as $key) {
                $match = $figi[$key] ?? $this->yahooLookup($key);
                DB::table('security_mappings')->upsert([[
                    'input_key' => $key,
                    'symbol' => $match['symbol'] ?? null,
                    'data' => json_encode($match),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]], ['input_key'], ['symbol', 'data', 'updated_at']);

                if ($match) {
                    $security = Security::firstOrNew(['symbol' => $match['symbol']]);
                    $security->name = $security->name ?: (string) ($match['name'] ?? $match['symbol']);
                    $security->exchange ??= $match['exchange'] ?? null;
                    $security->isin ??= self::isIsin($key) ? $key : null;
                    $security->wkn ??= self::isWkn($key) ? $key : null;
                    $security->save();
                    $resolved[$key] = $security;
                }
            }
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{symbol: string, name: string, exchange: string}>
     */
    private function openFigi(array $keys): array
    {
        try {
            $request = Http::timeout(20)->acceptJson();

            if (filled(config('services.openfigi.key'))) {
                $request = $request->withHeaders(['X-OPENFIGI-APIKEY' => config('services.openfigi.key')]);
            }

            $response = $request->post(self::OPENFIGI_URL, array_map(fn (string $key) => [
                'idType' => self::isIsin($key) ? 'ID_ISIN' : 'ID_WERTPAPIER',
                'idValue' => $key,
            ], $keys));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $results = [];

        foreach ((array) $response->json() as $index => $job) {
            $candidates = collect($job['data'] ?? [])->filter(fn ($item) => ! empty($item['ticker']) && array_key_exists($item['exchCode'] ?? '', self::EXCHANGE_SUFFIXES));
            $best = $candidates->sortBy(fn ($item) => array_search($item['exchCode'], array_keys(self::EXCHANGE_SUFFIXES), true))->first();

            if ($best && isset($keys[$index])) {
                $results[$keys[$index]] = [
                    'symbol' => strtoupper(str_replace(' ', '-', $best['ticker'])).self::EXCHANGE_SUFFIXES[$best['exchCode']],
                    'name' => (string) ($best['name'] ?? $best['ticker']),
                    'exchange' => (string) $best['exchCode'],
                ];
            }
        }

        return $results;
    }

    /**
     * @return array{symbol: string, name: string, exchange: string}|null
     */
    private function yahooLookup(string $key): ?array
    {
        try {
            $quotes = collect((array) Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(4)
                ->get(self::SEARCH_URL, ['q' => $key, 'quotesCount' => 10, 'newsCount' => 0])->json('quotes'))
                ->filter(fn ($quote) => ! empty($quote['symbol']));
        } catch (Throwable) {
            return null;
        }

        $best = $quotes->first(fn ($quote) => (bool) preg_match('/\.(DE|F|SG|MU|HM|DU|BE)$/', $quote['symbol'])) ?? $quotes->first();

        return $best ? [
            'symbol' => strtoupper((string) $best['symbol']),
            'name' => (string) ($best['longname'] ?? $best['shortname'] ?? $best['symbol']),
            'exchange' => (string) ($best['exchDisp'] ?? ''),
        ] : null;
    }

    // ── Fundamentals & market scan ───────────────────────────────────────

    /**
     * Key figures from Yahoo (P/E, debt/equity, growth, margins, analyst view, profile), cached
     * on the security for a week. Needs Yahoo's cookie + crumb.
     *
     * @return array<string, mixed>|null
     */
    public function fundamentals(Security $security, bool $refresh = false): ?array
    {
        $complete = $security->fundamentals === null || array_key_exists('price_to_cashflow', $security->fundamentals);

        if (! $refresh && $complete && $security->fundamentals_updated_at?->gt(now()->subWeek())) {
            return $security->fundamentals;
        }

        if (str_starts_with($security->symbol, '^') || str_contains($security->symbol, '=')) {
            return null;
        }

        $data = $this->yahooJson(self::SUMMARY_URL.rawurlencode($security->symbol), ['modules' => 'summaryDetail,financialData,defaultKeyStatistics,assetProfile,insiderTransactions,netSharePurchaseActivity,earnings,recommendationTrend,upgradeDowngradeHistory,calendarEvents']);
        $result = $data['quoteSummary']['result'][0] ?? null;

        if (! $result) {
            // Remember the attempt so that failing symbols are not asked every run.
            $security->forceFill(['fundamentals_updated_at' => now()->subDays(5)])->save();

            return $security->fundamentals;
        }

        $raw = fn ($field) => is_array($field) ? (isset($field['raw']) ? (float) $field['raw'] : null) : (is_numeric($field) ? (float) $field : null);
        $summary = $result['summaryDetail'] ?? [];
        $financial = $result['financialData'] ?? [];
        $statistics = $result['defaultKeyStatistics'] ?? [];
        $profile = $result['assetProfile'] ?? [];

        $fundamentals = [
            'pe_ratio' => $raw($summary['trailingPE'] ?? null),
            'forward_pe' => $raw($summary['forwardPE'] ?? null),
            'price_to_book' => $raw($statistics['priceToBook'] ?? null),
            'dividend_yield' => $raw($summary['dividendYield'] ?? null),
            'debt_to_equity' => ($value = $raw($financial['debtToEquity'] ?? null)) !== null ? $value / 100 : null,
            'return_on_equity' => $raw($financial['returnOnEquity'] ?? null),
            'profit_margins' => $raw($financial['profitMargins'] ?? null),
            'operating_margins' => $raw($financial['operatingMargins'] ?? null),
            'revenue_growth' => $raw($financial['revenueGrowth'] ?? null),
            'earnings_growth' => $raw($financial['earningsGrowth'] ?? null),
            'recommendation' => $financial['recommendationKey'] ?? null,
            'analyst_target' => $raw($financial['targetMeanPrice'] ?? null),
            'analyst_count' => $raw($financial['numberOfAnalystOpinions'] ?? null),
            'industry' => $profile['industry'] ?? null,
            'employees' => $profile['fullTimeEmployees'] ?? null,
            'website' => $profile['website'] ?? null,
            'description' => isset($profile['longBusinessSummary']) ? mb_substr((string) $profile['longBusinessSummary'], 0, 2000) : null,
            'ir_website' => isset($profile['irWebsite']) && str_starts_with((string) $profile['irWebsite'], 'http') ? mb_substr((string) $profile['irWebsite'], 0, 255) : null,
        ] + self::cashFigures($summary, $financial, $statistics, $raw) + self::insightsFrom($result, $raw);
        $fundamentals['history'] = self::figureHistory((array) ($security->fundamentals['history'] ?? []), $fundamentals, $security->last_price);

        $security->fill(array_filter([
            'sector' => $profile['sector'] ?? null,
            'country' => $profile['country'] ?? null,
            'market_cap' => ($cap = $raw($summary['marketCap'] ?? null)) ? (int) $cap : null,
            'dividend_yield' => $fundamentals['dividend_yield'] !== null ? round($fundamentals['dividend_yield'] * 100, 4) : null,
            'beta' => $raw($summary['beta'] ?? null),
        ], fn ($value) => $value !== null))->forceFill(['fundamentals' => $fundamentals, 'fundamentals_updated_at' => now()])->save();

        return $fundamentals;
    }

    /**
     * EBIT, EBITDA, cash flows and the valuation by them (price/cash flow, EV/EBITDA, free cash flow yield).
     *
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $financial
     * @param  array<string, mixed>  $statistics
     * @param  callable(mixed): ?float  $raw
     * @return array{total_revenue: ?float, ebitda: ?float, ebit: ?float, operating_cashflow: ?float, free_cashflow: ?float, ev_to_ebitda: ?float, price_to_cashflow: ?float, free_cashflow_yield: ?float}
     */
    public static function cashFigures(array $summary, array $financial, array $statistics, callable $raw): array
    {
        $revenue = $raw($financial['totalRevenue'] ?? null);
        $margin = $raw($financial['operatingMargins'] ?? null);
        $cashflow = $raw($financial['operatingCashflow'] ?? null);
        $free = $raw($financial['freeCashflow'] ?? null);
        $cap = $raw($summary['marketCap'] ?? null);

        return [
            'total_revenue' => $revenue,
            'ebitda' => $raw($financial['ebitda'] ?? null),
            'ebit' => $revenue !== null && $margin !== null ? round($revenue * $margin) : null,
            'operating_cashflow' => $cashflow,
            'free_cashflow' => $free,
            'ev_to_ebitda' => $raw($statistics['enterpriseToEbitda'] ?? null),
            'price_to_cashflow' => $cap && $cashflow && $cashflow > 0 ? round($cap / $cashflow, 2) : null,
            'free_cashflow_yield' => $cap && $free !== null ? round($free / $cap, 4) : null,
        ];
    }

    /**
     * Keeps the valuation figures over time: one point per week, the last five years.
     *
     * @param  array<int, mixed>  $history
     * @param  array<string, mixed>  $figures
     * @return list<array{date: string, price: ?float, pe_ratio: ?float, price_to_cashflow: ?float, ev_to_ebitda: ?float, dividend_yield: ?float}>
     */
    public static function figureHistory(array $history, array $figures, ?float $price): array
    {
        $week = today()->startOfWeek()->toDateString();
        $points = collect($history)->filter(fn ($point) => is_array($point) && isset($point['date']) && $point['date'] < $week)->values();
        $points->push([
            'date' => today()->toDateString(),
            'price' => $price,
            'pe_ratio' => $figures['pe_ratio'] ?? null,
            'price_to_cashflow' => $figures['price_to_cashflow'] ?? null,
            'ev_to_ebitda' => $figures['ev_to_ebitda'] ?? null,
            'dividend_yield' => $figures['dividend_yield'] ?? null,
        ]);

        return $points->take(-260)->values()->all();
    }

    /**
     * Insider trades, yearly revenue and earnings, analyst votes, rating changes and the next
     * earnings date from Yahoo's quote summary.
     *
     * @param  array<string, mixed>  $result
     * @param  callable(mixed): ?float  $raw
     * @return array{insider: ?array{period: string, buys: int, sells: int, net_shares: ?float, transactions: list<array{name: string, relation: string, date: ?string, text: string, shares: ?float, value: ?float}>}, financials: list<array{year: string, revenue: ?float, earnings: ?float}>, analyst_votes: ?array{strong_buy: int, buy: int, hold: int, sell: int, strong_sell: int}, rating_changes: list<array{date: string, firm: string, from: string, to: string, action: string}>, next_earnings: ?string}
     */
    public static function insightsFrom(array $result, callable $raw): array
    {
        $activity = $result['netSharePurchaseActivity'] ?? null;
        $transactions = collect($result['insiderTransactions']['transactions'] ?? [])->take(10)->map(fn (array $trade) => [
            'name' => mb_substr((string) ($trade['filerName'] ?? ''), 0, 80),
            'relation' => mb_substr((string) ($trade['filerRelation'] ?? ''), 0, 60),
            'date' => isset($trade['startDate']['raw']) ? date('Y-m-d', (int) $trade['startDate']['raw']) : null,
            'text' => mb_substr((string) ($trade['transactionText'] ?? ''), 0, 160),
            'shares' => $raw($trade['shares'] ?? null),
            'value' => $raw($trade['value'] ?? null),
        ])->values()->all();

        $votes = collect($result['recommendationTrend']['trend'] ?? [])->firstWhere('period', '0m');
        $yearly = $result['earnings']['financialsChart']['yearly'] ?? [];

        return [
            'insider' => is_array($activity) || $transactions !== [] ? [
                'period' => (string) ($activity['period'] ?? '6m'),
                'buys' => (int) ($raw($activity['buyInfoCount'] ?? null) ?? 0),
                'sells' => (int) ($raw($activity['sellInfoCount'] ?? null) ?? 0),
                'net_shares' => $raw($activity['netInfoShares'] ?? null),
                'transactions' => $transactions,
            ] : null,
            'financials' => collect($yearly)->take(-5)->map(fn (array $year) => [
                'year' => (string) ($year['date'] ?? ''),
                'revenue' => $raw($year['revenue'] ?? null),
                'earnings' => $raw($year['earnings'] ?? null),
            ])->filter(fn (array $year) => $year['year'] !== '')->values()->all(),
            'analyst_votes' => is_array($votes) ? [
                'strong_buy' => (int) ($votes['strongBuy'] ?? 0),
                'buy' => (int) ($votes['buy'] ?? 0),
                'hold' => (int) ($votes['hold'] ?? 0),
                'sell' => (int) ($votes['sell'] ?? 0),
                'strong_sell' => (int) ($votes['strongSell'] ?? 0),
            ] : null,
            'rating_changes' => collect($result['upgradeDowngradeHistory']['history'] ?? [])->take(6)->map(fn (array $change) => [
                'date' => date('Y-m-d', (int) ($change['epochGradeDate'] ?? 0)),
                'firm' => mb_substr((string) ($change['firm'] ?? ''), 0, 60),
                'from' => mb_substr((string) ($change['fromGrade'] ?? ''), 0, 40),
                'to' => mb_substr((string) ($change['toGrade'] ?? ''), 0, 40),
                'action' => (string) ($change['action'] ?? ''),
            ])->filter(fn (array $change) => $change['firm'] !== '')->values()->all(),
            'next_earnings' => isset($result['calendarEvents']['earnings']['earningsDate'][0]['raw'])
                ? date('Y-m-d', (int) $result['calendarEvents']['earnings']['earningsDate'][0]['raw'])
                : null,
        ];
    }

    /**
     * Securities from Yahoo's predefined screeners; stored and tagged with the screener names.
     *
     * @param  list<string>  $screeners
     * @return list<string> symbols found
     */
    public function scan(array $screeners = self::SCREENERS, int $perScreener = 25): array
    {
        $found = [];

        foreach ($screeners as $screener) {
            $data = $this->yahooJson(self::SCREENER_URL, ['formatted' => 'false', 'lang' => 'en-US', 'region' => 'US', 'scrIds' => $screener, 'start' => 0, 'count' => $perScreener]);

            foreach ($data['finance']['result'][0]['quotes'] ?? [] as $quote) {
                $symbol = strtoupper((string) ($quote['symbol'] ?? ''));

                if ($symbol === '' || strtolower((string) ($quote['quoteType'] ?? 'equity')) !== 'equity') {
                    continue;
                }

                $security = Security::firstOrNew(['symbol' => mb_substr($symbol, 0, 32)]);
                $security->fill(array_filter([
                    'name' => $security->name ?: ($quote['longName'] ?? $quote['shortName'] ?? $symbol),
                    'exchange' => $security->exchange ?: ($quote['fullExchangeName'] ?? null),
                    'currency' => $quote['currency'] ?? null,
                    'market_cap' => isset($quote['marketCap']) ? (int) $quote['marketCap'] : null,
                    'last_price' => isset($quote['regularMarketPrice']) ? (float) $quote['regularMarketPrice'] : null,
                    'previous_close' => isset($quote['regularMarketPreviousClose']) ? (float) $quote['regularMarketPreviousClose'] : null,
                ], fn ($value) => $value !== null && $value !== ''));
                $security->asset_type ??= 'stock';
                $security->screeners = array_values(array_unique([...($found[$symbol] ?? false ? $security->screeners ?? [] : []), $screener]));
                $security->scanned_at = now();

                if ($security->isDirty('last_price')) {
                    $security->price_updated_at = now();
                }

                $security->save();
                $found[$symbol] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * JSON from a Yahoo endpoint that needs the session cookie and crumb.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function yahooJson(string $url, array $query): ?array
    {
        $session = $this->yahooSession();

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'])
                ->withOptions(['cookies' => $session['jar']])->timeout(10)
                ->get($url, $query + array_filter(['crumb' => $session['crumb']]));

            if ($response->status() === 401 && $session['crumb']) {
                Cache::forget('portfolio:yahoo-session');
            }

            return $response->successful() ? (array) $response->json() : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return array{jar: CookieJar, crumb: ?string}
     */
    private function yahooSession(): array
    {
        $cached = Cache::get('portfolio:yahoo-session');

        if (is_array($cached) && isset($cached['cookies'])) {
            return ['jar' => new CookieJar(false, $cached['cookies']), 'crumb' => $cached['crumb'] ?? null];
        }

        $jar = new CookieJar;
        $crumb = null;

        try {
            Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html'])->withOptions(['cookies' => $jar])->timeout(10)->get('https://fc.yahoo.com');
        } catch (Throwable) {
            // fc.yahoo.com answers 404 but sets the cookie.
        }

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Referer' => 'https://finance.yahoo.com/'])->withOptions(['cookies' => $jar])->timeout(8)
                ->get('https://query1.finance.yahoo.com/v1/test/getcrumb');
            $body = trim($response->body());
            $crumb = $response->successful() && strlen($body) > 1 && strlen($body) < 100 && ! str_contains($body, '<') ? $body : null;
        } catch (Throwable) {
            $crumb = null;
        }

        Cache::put('portfolio:yahoo-session', ['cookies' => $jar->toArray(), 'crumb' => $crumb], $crumb ? now()->addHours(6) : now()->addMinutes(15));

        return ['jar' => $jar, 'crumb' => $crumb];
    }
}
