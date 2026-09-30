<?php

namespace App\Support\Portfolio\Import;

/**
 * Generischer CSV-Parser – flexibel konfigurierbar.
 *
 * Erwartet mindestens diese Spalten (beliebige Reihenfolge, case-insensitiv):
 *   symbol/ticker, date/datum, type/typ, quantity/menge/anzahl, price/kurs
 *
 * Optional: name, isin, fees/gebühren, taxes/steuern, currency/währung, notes
 */
class GenericCsvParser extends AbstractParser
{
    private const TYPE_MAP = [
        'kauf' => 'buy',    'buy' => 'buy',
        'k' => 'buy',    'b' => 'buy',
        'verkauf' => 'sell',   'sell' => 'sell',
        'v' => 'sell',   's' => 'sell',
        'dividende' => 'dividend', 'dividend' => 'dividend',
        'div' => 'dividend',
        'split' => 'split',
        'transfer_in' => 'transfer_in',
        'transfer_out' => 'transfer_out',
    ];

    private const COL_ALIASES = [
        'symbol' => ['symbol', 'ticker'],
        'name' => ['name', 'wertpapier', 'bezeichnung', 'wertpapierbezeichnung'],
        'isin' => ['isin'],
        'wkn' => ['wkn', 'wertpapierkennnummer'],
        'date' => ['date', 'datum', 'buchungsdatum', 'handelstag', 'buchungstag'],
        'type' => ['type', 'typ', 'art', 'geschäftsart', 'vorgang'],
        'quantity' => ['quantity', 'menge', 'anzahl', 'nominale', 'stück', 'qty'],
        'price' => ['price', 'kurs', 'preis', 'kaufkurs'],
        'fees' => ['fees', 'gebühren', 'provision', 'kosten', 'transaktionskosten'],
        'taxes' => ['taxes', 'steuern', 'kapitalertragsteuer'],
        'currency' => ['currency', 'währung', 'ccy'],
        'notes' => ['notes', 'notizen', 'kommentar', 'verwendungszweck'],
    ];

    /**
     * Returns raw header names and up to 5 sample rows for mapping UI.
     *
     * @return array ['headers' => string[], 'samples' => array[], 'auto_mapping' => array]
     */
    public static function analyze(string $csvContent): array
    {
        $parser = new self;
        $sep = $parser->detectSeparator($csvContent);
        $rows = $parser->parseCsvLines($csvContent, $sep);

        if (empty($rows)) {
            return ['headers' => [], 'samples' => [], 'auto_mapping' => []];
        }

        $headers = array_map('trim', $rows[0]);
        $headerLow = array_map('strtolower', $headers);
        $samples = array_slice($rows, 1, 5);

        // Build auto-detected mapping (field => column index)
        $autoMap = [];
        foreach (self::COL_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $idx = array_search($alias, $headerLow);
                if ($idx !== false) {
                    $autoMap[$field] = $idx;
                    break;
                }
            }
        }

        return [
            'headers' => $headers,
            'samples' => $samples,
            'auto_mapping' => $autoMap,
        ];
    }

    /**
     * Parse using a custom column mapping (field => column_index).
     */
    public function parseWithMapping(string $csvContent, array $customColMap): array
    {
        $sep = $this->detectSeparator($csvContent);
        $rows = $this->parseCsvLines($csvContent, $sep);

        if (count($rows) < 2) {
            $this->errors[] = __('No data found.');

            return $this->result([]);
        }

        $colMap = $customColMap;

        if (! isset($colMap['symbol']) && ! isset($colMap['isin']) && ! isset($colMap['wkn'])) {
            $this->errors[] = __('Please assign a column for symbol, ISIN or WKN.');

            return $this->result([]);
        }

        $transactions = [];
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (count($row) < 2 || trim($row[0] ?? '') === '') {
                continue;
            }
            try {
                $transactions[] = $this->buildTransaction($row, $colMap, $i);
            } catch (\Throwable $e) {
                $this->errors[] = __('Line :line: invalid data.', ['line' => $i]);
            }
        }

        return $this->result(array_filter($transactions));
    }

    public function parse(string $csvContent): array
    {
        // Trennzeichen auto-erkennen
        $sep = $this->detectSeparator($csvContent);
        $rows = $this->parseCsvLines($csvContent, $sep);

        if (count($rows) < 2) {
            $this->errors[] = __('No data found.');

            return $this->result([]);
        }

        // Header-Zeile finden
        $headerIdx = 0;
        $header = array_map('strtolower', array_map('trim', $rows[0]));

        // Spalten mappen
        $colMap = [];
        foreach (self::COL_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $idx = array_search($alias, $header);
                if ($idx !== false) {
                    $colMap[$field] = $idx;
                    break;
                }
            }
        }

        if (! isset($colMap['symbol']) && ! isset($colMap['isin']) && ! isset($colMap['wkn'])) {
            $this->errors[] = __('Please assign a column for symbol, ISIN or WKN.');

            return $this->result([]);
        }

        $transactions = [];

        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (count($row) < 2 || trim($row[0]) === '') {
                continue;
            }
            try {
                $tx = $this->buildTransaction($row, $colMap, $i);
                if ($tx) {
                    $transactions[] = $tx;
                }
            } catch (\Throwable $e) {
                $this->errors[] = __('Line :line: invalid data.', ['line' => $i]);
            }
        }

        return $this->result($transactions);
    }

    private function buildTransaction(array $row, array $colMap, int $lineNum): ?array
    {
        $get = fn (string $field, string $default = '') => isset($colMap[$field]) && isset($row[$colMap[$field]])
                ? trim((string) $row[$colMap[$field]])
                : $default;

        $symbol = $get('symbol');
        $isin = strtoupper($get('isin'));
        $wkn = strtoupper($get('wkn'));
        $name = $get('name');
        $rawDate = $get('date');
        $rawType = strtolower($get('type', 'buy'));
        $qty = $get('quantity') !== '' ? $this->parseAmount($get('quantity')) : 0;
        $price = $get('price') !== '' ? $this->parseAmount($get('price')) : 0;
        $fees = $get('fees') !== '' ? $this->parseAmount($get('fees')) : 0;
        $taxes = $get('taxes') !== '' ? $this->parseAmount($get('taxes')) : 0;
        $curr = strtoupper($get('currency', 'EUR')) ?: 'EUR';
        $notes = $get('notes');

        $type = self::TYPE_MAP[$rawType] ?? 'buy';
        $date = $this->parseGermanDate($rawDate);
        if (! $date && $rawDate) {
            $ts = strtotime($rawDate);
            $date = $ts ? date('Y-m-d', $ts) : null;
        }

        if (! $date || (! $symbol && ! $isin && ! $wkn)) {
            $this->errors[] = __('Line :line: date or symbol/ISIN/WKN missing.', ['line' => $lineNum]);

            return null;
        }

        // Symbol ableiten: ISIN > WKN > Symbol (wird später via OpenFIGI aufgelöst)
        $resolvedSymbol = $symbol ?: ($isin ?: $wkn);

        return [
            'symbol' => $resolvedSymbol,
            'name' => $name,
            'isin' => $isin ?: null,
            'wkn' => $wkn ?: null,
            'type' => $type,
            'quantity' => abs($qty),
            'price' => abs($price),
            'fees' => abs($fees),
            'taxes' => abs($taxes),
            'currency' => $curr,
            'exchange_rate' => 1,
            'transaction_date' => $date,
            'notes' => $notes,
            'broker' => 'Generic',
        ];
    }

    private function detectSeparator(string $content): string
    {
        $firstLine = strtok($content, "\n");
        $counts = [
            ';' => substr_count($firstLine, ';'),
            ',' => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($counts);

        return array_key_first($counts);
    }
}
