<?php

namespace App\Support\Portfolio;

use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Support\Portfolio\Import\ImportManager;
use Throwable;

/**
 * Imports parsed broker transactions into a portfolio: ISIN/WKN become symbols, duplicates
 * (same security, type, quantity and date) are skipped, then the positions are recalculated.
 */
class TransactionImporter
{
    public const BROKERS = [
        'auto' => 'Automatic',
        'trade_republic' => 'Trade Republic',
        'dkb' => 'DKB',
        'ing' => 'ING',
        'smartbroker' => 'Smartbroker+',
        'comdirect' => 'comdirect',
        'consorsbank' => 'Consorsbank',
        'flatex' => 'flatex',
        'onvista' => 'OnVista',
        'outbank' => 'Outbank',
        'finanzguru' => 'Finanzguru',
        'generic' => 'Other CSV',
    ];

    public function __construct(private MarketData $market) {}

    /**
     * @return array{broker: string, transactions: list<array<string, mixed>>, errors: list<string>}
     */
    public function parse(string $contents, string $broker = 'auto'): array
    {
        $broker = $broker === 'auto' || ! array_key_exists($broker, self::BROKERS) ? ImportManager::detectBroker($contents) : $broker;

        try {
            $result = ImportManager::parse($contents, $broker);
        } catch (Throwable $exception) {
            report($exception);
            $result = ['transactions' => [], 'errors' => [__('The file could not be read.')]];
        }

        $transactions = array_values(array_filter(array_map(fn (array $row) => $this->normalize($row), $result['transactions'] ?? [])));

        return ['broker' => $broker, 'transactions' => $transactions, 'errors' => array_values(array_slice($result['errors'] ?? [], 0, 20))];
    }

    /**
     * @param  list<array<string, mixed>>  $rows  normalized rows (see normalize())
     * @return array{imported: int, skipped: int}
     */
    public function import(Portfolio $portfolio, array $rows, string $source): array
    {
        $resolved = $this->market->resolveMany(collect($rows)->flatMap(fn (array $row) => [
            MarketData::isIsin($row['symbol']) || MarketData::isWkn($row['symbol']) ? $row['symbol'] : null,
            $row['isin'], $row['wkn'],
        ])->filter()->all());

        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $security = $resolved[$row['symbol']] ?? ($row['isin'] ? $resolved[$row['isin']] ?? null : null) ?? ($row['wkn'] ? $resolved[$row['wkn']] ?? null : null);

            if ($security && (MarketData::isIsin($row['symbol']) || MarketData::isWkn($row['symbol']) || $row['symbol'] === '')) {
                $row['isin'] ??= MarketData::isIsin($row['symbol']) ? $row['symbol'] : $security->isin;
                $row['symbol'] = $security->symbol;
                $row['name'] = $row['name'] ?: $security->name;
            }

            if ($row['symbol'] === '') {
                $skipped++;

                continue;
            }

            $duplicate = PortfolioTransaction::where('portfolio_id', $portfolio->id)
                ->where(fn ($query) => $query->where('symbol', $row['symbol'])->when($row['isin'], fn ($query) => $query->orWhere('isin', $row['isin'])))
                ->where('type', $row['type'])->whereDate('executed_on', $row['executed_on'])
                ->whereBetween('quantity', [$row['quantity'] - 0.000001, $row['quantity'] + 0.000001])
                ->exists();

            if ($duplicate) {
                $skipped++;

                continue;
            }

            $portfolio->transactions()->create($row + ['import_source' => $source]);
            $imported++;
        }

        Positions::recalculate($portfolio);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Parser output → transaction attributes, or null when unusable.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public function normalize(array $row): ?array
    {
        $isin = strtoupper(trim((string) ($row['isin'] ?? '')));
        $wkn = strtoupper(trim((string) ($row['wkn'] ?? '')));
        $symbol = strtoupper(trim((string) ($row['symbol'] ?? ''))) ?: $isin ?: $wkn;
        $date = (string) ($row['transaction_date'] ?? $row['executed_on'] ?? $row['date'] ?? '');
        $type = (string) ($row['type'] ?? 'buy');

        if ($symbol === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! in_array($type, PortfolioTransaction::TYPES, true)) {
            return null;
        }

        return [
            'symbol' => mb_substr($symbol, 0, 32),
            'name' => mb_substr(trim((string) ($row['name'] ?? '')), 0, 255),
            'isin' => MarketData::isIsin($isin) ? $isin : null,
            'wkn' => MarketData::isWkn($wkn) ? $wkn : null,
            'type' => $type,
            'quantity' => abs((float) ($row['quantity'] ?? 0)),
            'price' => abs((float) ($row['price'] ?? 0)),
            'fees' => abs((float) ($row['fees'] ?? 0)),
            'taxes' => abs((float) ($row['taxes'] ?? 0)),
            'currency' => strtoupper(mb_substr(trim((string) ($row['currency'] ?? 'EUR')) ?: 'EUR', 0, 3)),
            'exchange_rate' => (float) ($row['exchange_rate'] ?? 1) ?: 1,
            'executed_on' => $date,
            'broker' => isset($row['broker']) ? mb_substr((string) $row['broker'], 0, 50) : null,
            'notes' => isset($row['notes']) && $row['notes'] !== '' ? (string) $row['notes'] : null,
        ];
    }
}
