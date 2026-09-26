<?php

namespace App\Support\Portfolio\Import;

/**
 * Outbank CSV-Export Parser
 *
 * Outbank exportiert Kontoumsätze im Format:
 * Datum;Name;Verwendungszweck;Konto;Bank;Betrag;Währung
 *
 * Da Outbank kein dediziertes Depot-Format exportiert, werden
 * Wertpapierkäufe/-verkäufe anhand des Verwendungszwecks erkannt.
 */
class OutbankParser extends AbstractParser
{
    private const BUY_KEYWORDS = ['kauf', 'wertpapierkauf', 'wertpapier kauf', 'erwerb', 'einlösung rückzahlung'];

    private const SELL_KEYWORDS = ['verkauf', 'wertpapierverkauf', 'wertpapier verkauf', 'veräußerung'];

    private const DIV_KEYWORDS = ['dividende', 'ausschüttung', 'zinsen', 'ertrag'];

    public function parse(string $csvContent): array
    {
        $rows = $this->parseCsvLines($csvContent, ';');
        if (empty($rows)) {
            return $this->result([]);
        }

        // Header-Zeile finden (erste Zeile mit 'Datum' oder 'Buchungsdatum')
        $headerIdx = 0;
        foreach ($rows as $i => $row) {
            $line = implode(';', array_map('strtolower', $row));
            if (str_contains($line, 'datum') || str_contains($line, 'betrag')) {
                $headerIdx = $i;
                break;
            }
        }

        $headers = array_map('strtolower', array_map('trim', $rows[$headerIdx]));
        $col = fn (string $name) => array_search($name, $headers, true);

        $colDate = $col('datum') ?? $col('buchungsdatum') ?? 0;
        $colName = $col('name') ?? $col('empfänger/absender') ?? 1;
        $colDesc = $col('verwendungszweck') ?? $col('buchungstext') ?? 2;
        $colAmount = $col('betrag') ?? 5;
        $colCur = $col('währung') ?? 6;

        $transactions = [];
        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (count($row) < 4) {
                continue;
            }

            $desc = strtolower(trim($row[$colDesc] ?? ''));
            $type = $this->detectType($desc);
            if (! $type) {
                continue;
            }

            $date = $this->parseGermanDate(trim($row[$colDate] ?? ''));
            if (! $date) {
                continue;
            }

            $amount = abs($this->parseAmount($row[$colAmount] ?? '0'));
            $name = trim($row[$colName] ?? '');
            $cur = strtoupper(trim($row[$colCur] ?? 'EUR'));

            // Symbol aus Name oder Verwendungszweck extrahieren (grobe Heuristik)
            $symbol = $this->extractSymbol($name.' '.($row[$colDesc] ?? ''));

            if (! $symbol && ! $name) {
                continue;
            }

            $transactions[] = [
                'symbol' => $symbol ?: strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 6)),
                'name' => $name,
                'date' => $date,
                'type' => $type,
                'quantity' => 0,       // Outbank liefert keine Stückzahl
                'price' => $amount, // Gesamtbetrag als Einzelpreis (Menge = 1)
                'fees' => 0,
                'currency' => $cur,
                'notes' => trim($row[$colDesc] ?? ''),
            ];
        }

        return $this->result($transactions);
    }

    private function detectType(string $desc): ?string
    {
        foreach (self::BUY_KEYWORDS as $kw) {
            if (str_contains($desc, $kw)) {
                return 'buy';
            }
        }
        foreach (self::SELL_KEYWORDS as $kw) {
            if (str_contains($desc, $kw)) {
                return 'sell';
            }
        }
        foreach (self::DIV_KEYWORDS as $kw) {
            if (str_contains($desc, $kw)) {
                return 'dividend';
            }
        }

        return null;
    }

    private function extractSymbol(string $text): string
    {
        // ISIN-Muster: DE000XXXXXXX oder US0000000000
        if (preg_match('/\b([A-Z]{2}[A-Z0-9]{10})\b/', strtoupper($text), $m)) {
            return $m[1];
        }
        // WKN: 6 alphanumerische Zeichen in Klammern
        if (preg_match('/\(([A-Z0-9]{6})\)/', strtoupper($text), $m)) {
            return $m[1];
        }
        // Börsen-Ticker in Klammern: (AAPL), (SAP), (MSFT)
        if (preg_match('/\(([A-Z]{2,6}(?:\.[A-Z]{2})?)\)/', strtoupper($text), $m)) {
            return $m[1];
        }

        return '';
    }
}
