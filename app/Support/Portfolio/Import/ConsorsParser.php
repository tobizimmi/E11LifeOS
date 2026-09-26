<?php

namespace App\Support\Portfolio\Import;

/**
 * Consorsbank CSV-Export Parser
 *
 * Consorsbank (BNP Paribas) exportiert Depot-Umsätze als CSV/Excel.
 *
 * Typisches Format:
 *   Orderreferenz;Handelsdatum;Uhrzeit;Produktart;ISIN;WKN;Name;Menge;Kurs;Handelswährung;Kurswert;Provision;Gesamtbetrag;Währung
 *
 * Alternativ-Header (deutsches Format):
 *   "Datum";"Orderreferenz";"Produktbeschreibung";"ISIN/WKN";"Anzahl";"Kurs";"Kurswert (EUR)";"Provision";"Gesamtbetrag (EUR)";"Typ"
 */
class ConsorsParser extends AbstractParser
{
    private const TYPE_MAP = [
        'kauf' => 'buy',
        'k' => 'buy',
        'kaufen' => 'buy',
        'wertpapierkauf' => 'buy',
        'sparplanausführung' => 'buy',
        'sparplan' => 'buy',
        'depot - einlieferung' => 'transfer_in',
        'einlieferung' => 'transfer_in',
        'verkauf' => 'sell',
        'v' => 'sell',
        'verkaufen' => 'sell',
        'wertpapierverkauf' => 'sell',
        'auslieferung' => 'transfer_out',
        'depot - auslieferung' => 'transfer_out',
        'dividende' => 'dividend',
        'dividendengutschrift' => 'dividend',
        'kapitalrückzahlung' => 'dividend',
        'ausschüttung' => 'dividend',
        'zinsgutschrift' => 'dividend',
        'zinsen' => 'dividend',
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
            if ((in_array('isin', $norm) || in_array('isin/wkn', $norm) || in_array('wkn', $norm))
                && (in_array('kurs', $norm) || in_array('menge', $norm) || in_array('anzahl', $norm))) {
                $header = $norm;
                $headerIdx = $i;
                break;
            }
        }

        if ($headerIdx < 0) {
            $this->errors[] = __('No :broker format recognised. Please export the securities account transactions.', ['broker' => 'Consorsbank']);

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
            $date = $this->findCol($r, ['handelsdatum', 'datum', 'buchungstag', 'buchungsdatum', 'abschlussdatum']);
            $date = $this->parseDate($date);
            if (! $date) {
                continue;
            }

            // Type
            $rawType = strtolower($this->findCol($r, ['produktart', 'typ', 'type', 'umsatzart', 'buchungstext', 'art', 'ordertyp']));
            $type = 'buy';
            foreach (self::TYPE_MAP as $k => $v) {
                if (str_contains($rawType, $k)) {
                    $type = $v;
                    break;
                }
            }

            // ISIN / Symbol
            $isin = strtoupper(trim($this->findCol($r, ['isin', 'isin/wkn'])));
            $wkn = strtoupper(trim($this->findCol($r, ['wkn'])));
            $symbol = $isin ?: $wkn;
            if (! $symbol) {
                continue;
            }

            // Name
            $name = $this->findCol($r, ['name', 'produktbeschreibung', 'bezeichnung', 'wertpapierbezeichnung', 'wertpapiername']);

            // Quantity
            $qty = $this->parseNumber($this->findCol($r, ['menge', 'anzahl', 'stück', 'nominale', 'stücke', 'anzahl stücke']));
            if ($qty <= 0 && $type !== 'fee') {
                continue;
            }

            // Price
            $price = $this->parseNumber($this->findCol($r, ['kurs', 'handelskurs', 'preis', 'ausführungskurs']));
            $kurswert = abs($this->parseNumber($this->findCol($r, ['kurswert', 'kurswert (eur)', 'handelswert'])));
            if ($price <= 0 && $qty > 0 && $kurswert > 0) {
                $price = $kurswert / $qty;
            }
            if ($price <= 0) {
                continue;
            }

            // Fees
            $fees = abs($this->parseNumber($this->findCol($r, ['provision', 'gebühren', 'transaktionskosten', 'gesamtprovision'])));

            // Currency
            $currency = strtoupper(trim($this->findCol($r, ['handelswährung', 'währung', 'currency']))) ?: 'EUR';
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
                'broker' => 'consorsbank',
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
