<?php

namespace App\Support\Portfolio\Import;

/**
 * DKB Broker CSV-Export Parser
 *
 * DKB exportiert Depotauszüge als CSV mit Semikolon-Trennung und deutschen Anführungszeichen.
 * Format-Beispiel:
 * "Buchungsdatum";"Geschäftsart";"Wertpapierbezeichnung";"ISIN";"WKN";"Kurs";"Währung Kurs";"Nominale";"Kurswert";"Währung Kurswert";"Provision";"Währung Provision"
 */
class DKBParser extends AbstractParser
{
    private const TYPE_MAP = [
        'kauf' => 'buy',
        'kauf von wertpapieren' => 'buy',
        'sparplanausführung' => 'buy',
        'ausgabe' => 'buy',
        'verkauf' => 'sell',
        'verkauf von wertpapieren' => 'sell',
        'rücknahme' => 'sell',
        'dividende' => 'dividend',
        'dividendengutschrift' => 'dividend',
        'zinsgutschrift' => 'dividend',
        'ausschüttung' => 'dividend',
        'einlieferung' => 'transfer_in',
        'auslieferung' => 'transfer_out',
    ];

    public function parse(string $csvContent): array
    {
        // DKB hat oft mehrere Header-Zeilen mit Kontodaten, überspringen bis zur Spaltenüberschrift
        $rows = $this->parseCsvLines($csvContent, ';');
        if (count($rows) < 2) {
            $this->errors[] = __('No data found.');

            return $this->result([]);
        }

        $headerIdx = -1;
        $header = [];

        foreach ($rows as $i => $row) {
            $normalized = array_map(fn ($c) => strtolower(trim(str_replace('"', '', $c))), $row);
            if (in_array('isin', $normalized) && in_array('nominale', $normalized)) {
                $header = $normalized;
                $headerIdx = $i;
                break;
            }
            // Alternatives Format
            if (in_array('isin', $normalized) && (in_array('kurs', $normalized) || in_array('menge', $normalized))) {
                $header = $normalized;
                $headerIdx = $i;
                break;
            }
        }

        if ($headerIdx < 0) {
            $this->errors[] = __('No :broker format recognised. Please export the securities account transactions.', ['broker' => 'DKB']);

            return $this->result([]);
        }

        $colMap = array_flip($header);
        $transactions = [];

        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = array_map(fn ($c) => trim(str_replace('"', '', $c)), $rows[$i]);
            if (count($row) < 4 || empty($row[0])) {
                continue;
            }

            try {
                $rawType = strtolower($row[$colMap['geschäftsart'] ?? $colMap['art'] ?? $colMap['buchungstext'] ?? 1] ?? '');
                $type = null;
                foreach (self::TYPE_MAP as $k => $v) {
                    if (str_contains($rawType, $k)) {
                        $type = $v;
                        break;
                    }
                }
                if (! $type) {
                    continue;
                }

                $name = $row[$colMap['wertpapierbezeichnung'] ?? $colMap['bezeichnung'] ?? 2] ?? '';
                $isin = $row[$colMap['isin'] ?? 3] ?? '';
                $price = $this->parseAmount($row[$colMap['kurs'] ?? 5] ?? '0');
                $qty = $this->parseAmount($row[$colMap['nominale'] ?? $colMap['menge'] ?? 7] ?? '0');
                $fees = $this->parseAmount($row[$colMap['provision'] ?? $colMap['gebühren'] ?? 10] ?? '0');
                $curr = strtoupper($row[$colMap['währung kurs'] ?? $colMap['währung'] ?? 6] ?? 'EUR');
                $rawDate = $row[$colMap['buchungsdatum'] ?? $colMap['datum'] ?? 0] ?? '';
                $date = $this->parseGermanDate($rawDate);

                if (! $date || (! $isin && ! $name)) {
                    $this->errors[] = __('Line :line: invalid data.', ['line' => $i]);

                    continue;
                }

                // Symbol aus ISIN ableiten (Yahoo Finance nutzt ISIN.DE Format für manche deutschen Aktien)
                $symbol = $isin ?: preg_replace('/[^A-Z0-9.]/', '', strtoupper($name));

                $transactions[] = [
                    'symbol' => $symbol,
                    'name' => $name,
                    'isin' => $isin ?: null,
                    'type' => $type,
                    'quantity' => abs($qty),
                    'price' => abs($price),
                    'fees' => abs($fees),
                    'taxes' => 0,
                    'currency' => $curr ?: 'EUR',
                    'exchange_rate' => 1,
                    'transaction_date' => $date,
                    'broker' => 'DKB',
                ];
            } catch (\Throwable $e) {
                $this->errors[] = __('Line :line: invalid data.', ['line' => $i]);
            }
        }

        return $this->result($transactions);
    }
}
