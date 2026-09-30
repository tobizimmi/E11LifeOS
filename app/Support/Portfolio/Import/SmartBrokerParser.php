<?php

namespace App\Support\Portfolio\Import;

/**
 * SmartBroker+ CSV-Export Parser (Baader Bank Basis)
 *
 * SmartBroker+ nutzt Baader Bank als Abwicklungspartner.
 * Format ähnlich wie comdirect/flatex: Semikolon-getrennt, deutsche Datumsformate.
 */
class SmartBrokerParser extends AbstractParser
{
    private const TYPE_MAP = [
        'kauf' => 'buy',
        'kauf/zeichnung' => 'buy',
        'sparplanausführung' => 'buy',
        'verkauf' => 'sell',
        'verkauf/rücknahme' => 'sell',
        'dividende' => 'dividend',
        'dividendengutschrift' => 'dividend',
        'ausschüttung' => 'dividend',
        'zinsgutschrift' => 'dividend',
        'einbuchung' => 'transfer_in',
        'umbuchung' => 'transfer_in',
    ];

    public function parse(string $csvContent): array
    {
        $rows = $this->parseCsvLines($csvContent, ';');

        $headerIdx = -1;
        $header = [];

        foreach ($rows as $i => $row) {
            $norm = array_map(fn ($c) => strtolower(trim(str_replace(['"', "'"], '', $c))), $row);
            if ((in_array('isin', $norm) || in_array('wkn', $norm))
                && (in_array('menge', $norm) || in_array('nominale', $norm) || in_array('anzahl', $norm))
            ) {
                $header = $norm;
                $headerIdx = $i;
                break;
            }
        }

        if ($headerIdx < 0) {
            $this->errors[] = __('No :broker format recognised. Please export the securities account transactions.', ['broker' => 'Smartbroker+']);

            return $this->result([]);
        }

        $colMap = array_flip($header);
        $transactions = [];

        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = array_map(fn ($c) => trim(str_replace(['"', "'"], '', $c)), $rows[$i]);
            if (count($row) < 4 || empty($row[0])) {
                continue;
            }

            try {
                $rawType = strtolower($row[$colMap['geschäftsart'] ?? $colMap['art'] ?? $colMap['typ'] ?? 1] ?? '');
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

                $name = $row[$colMap['wertpapierbezeichnung'] ?? $colMap['name'] ?? $colMap['bezeichnung'] ?? 2] ?? '';
                $isin = $row[$colMap['isin'] ?? 3] ?? '';
                $qty = $this->parseAmount($row[$colMap['menge'] ?? $colMap['nominale'] ?? $colMap['anzahl'] ?? 5] ?? '0');
                $price = $this->parseAmount($row[$colMap['kurs'] ?? $colMap['preis'] ?? 6] ?? '0');
                $fees = $this->parseAmount($row[$colMap['provision'] ?? $colMap['gebühren'] ?? $colMap['transaktionskosten'] ?? ''] ?? '0');
                $curr = strtoupper($row[$colMap['währung'] ?? ''] ?? 'EUR');
                $rawDate = $row[$colMap['handelstag'] ?? $colMap['datum'] ?? $colMap['buchungsdatum'] ?? 0] ?? '';
                $date = $this->parseGermanDate($rawDate);

                if (! $date) {
                    $this->errors[] = __('Line :line: invalid data.', ['line' => $i]);

                    continue;
                }

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
                    'broker' => 'SmartBroker+',
                ];
            } catch (\Throwable $e) {
                $this->errors[] = __('Line :line: invalid data.', ['line' => $i]);
            }
        }

        return $this->result($transactions);
    }
}
