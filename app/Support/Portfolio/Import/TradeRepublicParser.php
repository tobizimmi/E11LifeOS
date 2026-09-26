<?php

namespace App\Support\Portfolio\Import;

/**
 * Trade Republic CSV-Export Parser
 *
 * Unterstützte Formate:
 * - Kontoauszug (Transaktionshistorie): Datum;Typ;Symbol;ISIN;Name;Menge;Kurs;Betrag;Gebühren;Währung
 * - Portfolio-Export: Name;ISIN;Symbol;Menge;Kaufkurs;Aktueller Kurs;Währung
 */
class TradeRepublicParser extends AbstractParser
{
    private const TYPE_MAP = [
        'kauf' => 'buy',
        'buy' => 'buy',
        'verkauf' => 'sell',
        'sell' => 'sell',
        'dividende' => 'dividend',
        'dividend' => 'dividend',
        'zinsen' => 'dividend',
        'split' => 'split',
        'einzahlung' => 'transfer_in',
        'auszahlung' => 'transfer_out',
        'savingsplan' => 'buy',
        'sparplan' => 'buy',
    ];

    public function parse(string $csvContent): array
    {
        $rows = $this->parseCsvLines($csvContent, ';');
        if (count($rows) < 2) {
            $this->errors[] = __('No data found.');

            return $this->result([]);
        }

        // Header-Zeile finden (erste Zeile mit bekannten Spalten)
        $headerIdx = 0;
        $header = [];
        foreach ($rows as $i => $row) {
            $normalized = array_map('strtolower', array_map('trim', $row));
            if (in_array('isin', $normalized) || in_array('symbol', $normalized)) {
                $header = $normalized;
                $headerIdx = $i;
                break;
            }
        }

        if (empty($header)) {
            $this->errors[] = __('No valid header row found.');

            return $this->result([]);
        }

        $colMap = array_flip($header);
        $transactions = [];

        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (count($row) < 3 || trim($row[0]) === '') {
                continue;
            }

            try {
                $type = strtolower(trim($row[$colMap['typ'] ?? $colMap['type'] ?? 1] ?? ''));
                $mappedType = self::TYPE_MAP[$type] ?? null;

                if (! $mappedType) {
                    continue;
                } // Unbekannte Typen überspringen

                $symbol = trim($row[$colMap['symbol'] ?? $colMap['ticker'] ?? 2] ?? '');
                $isin = trim($row[$colMap['isin'] ?? 3] ?? '');
                $name = trim($row[$colMap['name'] ?? 4] ?? '');
                $qty = $this->parseAmount($row[$colMap['menge'] ?? $colMap['anzahl'] ?? $colMap['quantity'] ?? 5] ?? '0');
                $price = $this->parseAmount($row[$colMap['kurs'] ?? $colMap['preis'] ?? $colMap['price'] ?? 6] ?? '0');
                $amount = $this->parseAmount($row[$colMap['betrag'] ?? $colMap['amount'] ?? 7] ?? '0');
                $fees = $this->parseAmount($row[$colMap['gebühren'] ?? $colMap['kosten'] ?? $colMap['fees'] ?? 8] ?? '0');
                $curr = strtoupper(trim($row[$colMap['währung'] ?? $colMap['currency'] ?? 9] ?? 'EUR'));
                $rawDate = trim($row[$colMap['datum'] ?? $colMap['date'] ?? 0] ?? '');
                $date = $this->parseGermanDate($rawDate);

                if (! $date || (! $symbol && ! $isin)) {
                    $this->errors[] = __('Line :line: invalid data.', ['line' => $i]);

                    continue;
                }

                // Falls kein expliziter Kurs, aus Betrag/Menge berechnen
                if ($price <= 0 && $qty > 0 && abs($amount) > 0) {
                    $price = abs($amount) / $qty;
                }

                $transactions[] = [
                    'symbol' => $symbol ?: $isin,
                    'name' => $name,
                    'isin' => $isin ?: null,
                    'type' => $mappedType,
                    'quantity' => abs($qty),
                    'price' => abs($price),
                    'fees' => abs($fees),
                    'taxes' => 0,
                    'currency' => $curr ?: 'EUR',
                    'exchange_rate' => 1,
                    'transaction_date' => $date,
                    'broker' => 'Trade Republic',
                ];
            } catch (\Throwable $e) {
                $this->errors[] = __('Line :line: invalid data.', ['line' => $i]);
            }
        }

        return $this->result($transactions);
    }
}
