<?php

namespace App\Support\Portfolio\Import;

/**
 * OnVista Bank CSV-Export Parser
 *
 * OnVista (und flatex-Gruppe) exportiert Depot-Transaktionen als CSV.
 *
 * Typisches Format:
 *   "Buchungsdatum";"Geschäftsart";"Wertpapier";"ISIN";"WKN";"Anzahl";"Kurs";"Kurswert";"Provision";"Gesamtbetrag";"Währung"
 *
 * Alternativ (neueres Format):
 *   Datum;Transaktionstyp;Titel;ISIN;Stück;Kurs;Wert;Gebühren;Gesamtbetrag;Währung
 */
class OnVistaParser extends AbstractParser
{
    private const TYPE_MAP = [
        'kauf' => 'buy',
        'wertpapierkauf' => 'buy',
        'sparplanausführung' => 'buy',
        'verkauf' => 'sell',
        'wertpapierverkauf' => 'sell',
        'dividende' => 'dividend',
        'dividendengutschrift' => 'dividend',
        'ausschüttung' => 'dividend',
        'zinsen' => 'dividend',
        'zinsgutschrift' => 'dividend',
        'einlieferung' => 'transfer_in',
        'auslieferung' => 'transfer_out',
        'depotübertrag' => 'transfer_in',
        'entgelt' => 'fee',
        'transaktionsentgelt' => 'fee',
    ];

    public function parse(string $csvContent): array
    {
        $rows = $this->parseCsvLines($csvContent, ';');
        if (count($rows) < 2) {
            $this->errors[] = __('No data found.');

            return $this->result([]);
        }

        // Find header row
        $headerIdx = -1;
        $header = [];
        foreach ($rows as $i => $row) {
            $norm = array_map(fn ($c) => strtolower(trim(str_replace(['"', "\xEF\xBB\xBF"], '', $c))), $row);
            if (in_array('isin', $norm)
                && (in_array('anzahl', $norm) || in_array('stück', $norm) || in_array('nominale', $norm))
                && (in_array('kurs', $norm) || in_array('kurswert', $norm))) {
                $header = $norm;
                $headerIdx = $i;
                break;
            }
        }

        if ($headerIdx < 0) {
            $this->errors[] = __('No :broker format recognised. Please export the securities account transactions.', ['broker' => 'OnVista']);

            return $this->result([]);
        }

        $transactions = [];

        foreach (array_slice($rows, $headerIdx + 1) as $row) {
            if (count($row) < 4) {
                continue;
            }
            $r = array_combine(
                array_slice($header, 0, count($row)),
                array_map(fn ($v) => trim(str_replace('"', '', $v)), $row)
            ) ?: [];

            // Date
            $date = $this->findCol($r, ['buchungsdatum', 'datum', 'handelstag', 'ausführungstag', 'valutadatum']);
            $date = $this->parseDate($date);
            if (! $date) {
                continue;
            }

            // Type
            $rawType = strtolower($this->findCol($r, ['geschäftsart', 'transaktionstyp', 'typ', 'art', 'umsatzart']));
            $type = 'buy';
            foreach (self::TYPE_MAP as $k => $v) {
                if (str_contains($rawType, $k)) {
                    $type = $v;
                    break;
                }
            }

            // ISIN / Symbol
            $isin = strtoupper(trim($this->findCol($r, ['isin'])));
            $wkn = strtoupper(trim($this->findCol($r, ['wkn'])));
            $symbol = $isin ?: $wkn;
            if (! $symbol) {
                continue;
            }

            // Name
            $name = $this->findCol($r, ['wertpapier', 'titel', 'name', 'wertpapierbezeichnung', 'bezeichnung']);

            // Quantity
            $qty = $this->parseNumber($this->findCol($r, ['anzahl', 'stück', 'nominale', 'menge']));
            if ($qty <= 0 && $type !== 'fee') {
                continue;
            }

            // Price
            $price = $this->parseNumber($this->findCol($r, ['kurs', 'ausführungskurs', 'handelskurs']));
            $kurswert = abs($this->parseNumber($this->findCol($r, ['kurswert', 'wert', 'handelswert'])));
            if ($price <= 0 && $qty > 0 && $kurswert > 0) {
                $price = $kurswert / $qty;
            }
            if ($price <= 0) {
                continue;
            }

            // Fees
            $fees = abs($this->parseNumber($this->findCol($r, ['provision', 'gebühren', 'entgelt', 'kosten', 'gesamtkosten'])));

            // Currency
            $currency = strtoupper(trim($this->findCol($r, ['währung', 'currency']))) ?: 'EUR';
            if (strlen($currency) !== 3) {
                $currency = 'EUR';
            }

            $transactions[] = [
                'symbol' => $symbol,
                'name' => $name,
                'isin' => $isin,
                'transaction_date' => $date,
                'type' => $type,
                'quantity' => $qty,
                'price' => $price,
                'fees' => $fees,
                'currency' => $currency,
                'broker' => 'onvista',
                'notes' => '',
            ];
        }

        return $this->result($transactions);
    }

    private function findCol(array $row, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && $row[$k] !== '') {
                return $row[$k];
            }
        }

        return '';
    }
}
