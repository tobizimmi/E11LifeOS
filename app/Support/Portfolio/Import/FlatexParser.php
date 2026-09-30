<?php

namespace App\Support\Portfolio\Import;

/**
 * flatex / FinTech Group CSV-Export Parser
 *
 * flatex (und Ableger wie Benk) exportiert Depot-Umsätze als CSV.
 *
 * Typisches Format (flatex-Export):
 *   "Buchungsdatum";"Geschäftsart";"Wertpapierbezeichnung";"ISIN";"WKN";"Nominale";"Kurs";"Kurswert";"Gesamtbetrag";"Währung"
 *
 * Älteres Format:
 *   Datum;Geschäftsvorfall;Name;ISIN;WKN;Anzahl;Kurs;Wert;Kosten;Gesamtwert;Währung
 *
 * Neues Format (flatex 2.0):
 *   Handelstag;Handelszeit;Titel;ISIN;WKN;Menge;Kurs;Handelswert;Provision;Gesamtbetrag;Währung;Typ
 */
class FlatexParser extends AbstractParser
{
    private const TYPE_MAP = [
        'kauf' => 'buy',
        'kauf von wertpapieren' => 'buy',
        'vvb kauf' => 'buy',
        'sparplanausführung' => 'buy',
        'verkauf' => 'sell',
        'verkauf von wertpapieren' => 'sell',
        'dividende' => 'dividend',
        'dividendengutschrift' => 'dividend',
        'ausschüttung' => 'dividend',
        'ertragsausschüttung' => 'dividend',
        'zinsgutschrift' => 'dividend',
        'einlieferung' => 'transfer_in',
        'auslieferung' => 'transfer_out',
        'depoteinlieferung' => 'transfer_in',
        'depotauslieferung' => 'transfer_out',
        'entgelt' => 'fee',
        'depotgebühr' => 'fee',
        'provision' => 'fee',
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
                && (in_array('nominale', $norm) || in_array('anzahl', $norm) || in_array('menge', $norm))
                && (in_array('kurs', $norm) || in_array('kurswert', $norm))) {
                $header = $norm;
                $headerIdx = $i;
                break;
            }
        }

        if ($headerIdx < 0) {
            $this->errors[] = __('No :broker format recognised. Please export the securities account transactions.', ['broker' => 'flatex']);

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
            $date = $this->findCol($r, ['buchungsdatum', 'handelstag', 'datum', 'valuta', 'ausführungstag']);
            $date = $this->parseDate($date);
            if (! $date) {
                continue;
            }

            // Type
            $rawType = strtolower($this->findCol($r, ['geschäftsart', 'geschäftsvorfall', 'typ', 'type', 'art', 'transaktionsart']));
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
            $name = $this->findCol($r, ['wertpapierbezeichnung', 'titel', 'name', 'bezeichnung', 'beschreibung']);

            // Quantity
            $qty = $this->parseNumber($this->findCol($r, ['nominale', 'anzahl', 'menge', 'stück']));
            if ($qty <= 0 && $type !== 'fee') {
                continue;
            }

            // Price
            $price = $this->parseNumber($this->findCol($r, ['kurs', 'ausführungskurs', 'handelskurs']));
            $kurswert = abs($this->parseNumber($this->findCol($r, ['kurswert', 'handelswert', 'wert'])));
            if ($price <= 0 && $qty > 0 && $kurswert > 0) {
                $price = $kurswert / $qty;
            }
            if ($price <= 0) {
                continue;
            }

            // Fees
            $fees = abs($this->parseNumber($this->findCol($r, ['provision', 'kosten', 'entgelt', 'gesamtkosten', 'transaktionskosten'])));

            // Currency
            $currency = strtoupper(trim($this->findCol($r, ['währung', 'currency', 'kurswährung']))) ?: 'EUR';
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
                'broker' => 'flatex',
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
