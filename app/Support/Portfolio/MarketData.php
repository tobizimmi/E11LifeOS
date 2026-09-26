<?php

namespace App\Support\Portfolio;

use App\Models\Security;
use App\Models\SecurityPrice;
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
}
