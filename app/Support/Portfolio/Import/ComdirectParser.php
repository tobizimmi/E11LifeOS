<?php

namespace App\Support\Portfolio\Import;

/**
 * comdirect Bank CSV-Export Parser
 *
 * comdirect exportiert Transaktionen als CSV mit Semikolon-Trennung.
 *
 * Typisches Format (Umsätze):
 *   Buchungstag;Wert;Gläubiger-ID;Mandatsreferenz;Kundenreferenz;Buchungstext;Betrag;Gläubiger-ID;IBAN;BIC
 *
 * Für Depot-Umsätze (Wertpapiertransaktionen):
 *   Geschäftsdatum;Auftragsnummer;Typ;Beschreibung;ISIN;Stück;Kurs;Kurswert;Provision;Gesamtbetrag;Währung
 *
 * Alternativformat (comdirect Depot-Export):
 *   "Buchungstag";"Umsatzart";"Umsatzbeschreibung";"ISIN";"Wertpapierbezeichnung";"Nominale";"Kurs";"Kurswert";"Provision";"Gesamtbetrag";"Währung"
 */
class ComdirectParser extends AbstractParser
{
    private const TYPE_MAP = [
        'kauf' => 'buy',
        'kauf von wertpapieren' => 'buy',
        'sparplanausführung' => 'buy',
        'ausgabe' => 'buy',
        'einlieferung' => 'transfer_in',
        'bucheinlieferung' => 'transfer_in',
        'verkauf' => 'sell',
        'verkauf von wertpapieren' => 'sell',
        'rücknahme' => 'sell',
        'auslieferung' => 'transfer_out',
        'buchauslieferung' => 'transfer_out',
        'dividende' => 'dividend',
        'dividendengutschrift' => 'dividend',
        'ausschüttung' => 'dividend',
        'zinsen' => 'dividend',
        'zinsgutschrift' => 'dividend',
        'quellensteuer' => 'fee',
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
            if ((in_array('isin', $norm) || in_array('wkn', $norm))
                && (in_array('nominale', $norm) || in_array('stück', $norm) || in_array('anzahl', $norm) || in_array('menge', $norm))) {
                $header = $norm;
                $headerIdx = $i;
                break;
            }
        }

        if ($headerIdx < 0) {
            $this->errors[] = __('No :broker format recognised. Please export the securities account transactions.', ['broker' => 'comdirect']);

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
            $date = $this->findCol($r, ['buchungstag', 'geschäftsdatum', 'datum', 'handelstag', 'handelsdatum']);
            $date = $this->parseDate($date);
            if (! $date) {
                continue;
            }

            // Type
            $rawType = strtolower($this->findCol($r, ['umsatzart', 'typ', 'geschäftsart', 'transaktion', 'buchungstext', 'art']));
            $type = 'buy';
            foreach (self::TYPE_MAP as $k => $v) {
                if (str_contains($rawType, $k)) {
                    $type = $v;
                    break;
                }
            }

            // Symbol / ISIN
            $isin = strtoupper(trim($this->findCol($r, ['isin'])));
            $symbol = strtoupper(trim($this->findCol($r, ['symbol', 'ticker', 'wkn'])));
            if (! $symbol && $isin) {
                $symbol = $isin;
            }
            if (! $symbol) {
                continue;
            }

            // Name
            $name = $this->findCol($r, ['wertpapierbezeichnung', 'bezeichnung', 'name', 'beschreibung', 'umsatzbeschreibung']);

            // Quantity
            $qty = $this->parseNumber($this->findCol($r, ['nominale', 'stück', 'anzahl', 'menge', 'stücke']));
            if ($qty <= 0 && $type !== 'fee') {
                continue;
            }

            // Price
            $price = $this->parseNumber($this->findCol($r, ['kurs', 'preis', 'einzelkurs']));
            // Gesamtbetrag fallback
            $gesamtbetrag = abs($this->parseNumber($this->findCol($r, ['gesamtbetrag', 'kurswert'])));
            if ($price <= 0 && $qty > 0 && $gesamtbetrag > 0) {
                $price = $gesamtbetrag / $qty;
            }
            if ($price <= 0 && $type === 'fee') {
                $price = $gesamtbetrag;
            }
            if ($price <= 0) {
                continue;
            }

            // Fees
            $fees = abs($this->parseNumber($this->findCol($r, ['provision', 'gebühren', 'kosten', 'transaktionskosten'])));

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
                'broker' => 'comdirect',
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
