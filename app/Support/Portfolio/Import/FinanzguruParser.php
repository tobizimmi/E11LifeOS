<?php

namespace App\Support\Portfolio\Import;

/**
 * Finanzguru CSV-Export Parser
 *
 * Finanzguru exportiert im Format:
 * Buchungsdatum;Betrag;Name;Verwendungszweck;Kategorie;Konto;Bankname
 *
 * Wertpapiertransaktionen werden anhand der Kategorie und des Verwendungszwecks erkannt.
 */
class FinanzguruParser extends AbstractParser
{
    private const BUY_PATTERNS = ['wertpapierkauf', 'kauf von', 'kauf wertpapier', 'erwerb', 'kaufauftrag'];

    private const SELL_PATTERNS = ['wertpapierverkauf', 'verkauf von', 'verkauf wertpapier', 'veräußerung', 'verkaufsauftrag'];

    private const DIV_PATTERNS = ['dividende', 'ausschüttung', 'dividendenzahlung', 'ertrag', 'zinsgutschrift'];

    private const BUY_CATEGORIES = ['kapitalanlage', 'wertpapiere', 'investment', 'aktien', 'fonds'];

    private const SELL_CATEGORIES = ['wertpapierverkauf'];

    public function parse(string $csvContent): array
    {
        $rows = $this->parseCsvLines($csvContent, ';');
        if (empty($rows)) {
            return $this->result([]);
        }

        // Header suchen
        $headerIdx = 0;
        foreach ($rows as $i => $row) {
            $line = strtolower(implode(';', $row));
            if (str_contains($line, 'buchungsdatum') || str_contains($line, 'betrag')) {
                $headerIdx = $i;
                break;
            }
        }

        $headers = array_map('strtolower', array_map('trim', $rows[$headerIdx]));
        $col = fn (string $n) => array_search($n, $headers, true);

        $colDate = $col('buchungsdatum') ?? $col('datum') ?? 0;
        $colAmt = $col('betrag') ?? 1;
        $colName = $col('name') ?? $col('empfänger') ?? 2;
        $colDesc = $col('verwendungszweck') ?? $col('buchungstext') ?? 3;
        $colCat = $col('kategorie') ?? 4;
        $colCur = $col('währung') ?? null;

        $transactions = [];
        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (count($row) < 3) {
                continue;
            }

            $desc = strtolower(trim($row[$colDesc] ?? ''));
            $cat = strtolower(trim($row[$colCat] ?? ''));
            $name = trim($row[$colName] ?? '');
            $type = $this->detectType($desc, $cat);
            if (! $type) {
                continue;
            }

            $date = $this->parseDate(trim($row[$colDate] ?? ''));
            if (! $date) {
                continue;
            }

            $raw = $this->parseAmount($row[$colAmt] ?? '0');
            $amount = abs($raw);
            $cur = $colCur !== null ? strtoupper(trim($row[$colCur] ?? 'EUR')) : 'EUR';
            $symbol = $this->extractSymbol($name.' '.($row[$colDesc] ?? ''));

            $transactions[] = [
                'symbol' => $symbol ?: strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 6)),
                'name' => $name,
                'date' => $date,
                'type' => $type,
                'quantity' => 0,       // Finanzguru liefert keine Stückzahl
                'price' => $amount, // Gesamtbetrag (Menge = 1)
                'fees' => 0,
                'currency' => $cur,
                'notes' => trim($row[$colDesc] ?? ''),
            ];
        }

        return $this->result($transactions);
    }

    private function detectType(string $desc, string $cat): ?string
    {
        foreach (self::BUY_PATTERNS as $p) {
            if (str_contains($desc, $p)) {
                return 'buy';
            }
        }
        foreach (self::SELL_PATTERNS as $p) {
            if (str_contains($desc, $p)) {
                return 'sell';
            }
        }
        foreach (self::DIV_PATTERNS as $p) {
            if (str_contains($desc, $p)) {
                return 'dividend';
            }
        }
        foreach (self::BUY_CATEGORIES as $c) {
            if (str_contains($cat, $c)) {
                return 'buy';
            }
        }
        foreach (self::SELL_CATEGORIES as $c) {
            if (str_contains($cat, $c)) {
                return 'sell';
            }
        }

        return null;
    }

    private function parseDate(string $raw): ?string
    {
        // YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }

        // DD.MM.YYYY
        return $this->parseGermanDate($raw);
    }

    private function extractSymbol(string $text): string
    {
        if (preg_match('/\b([A-Z]{2}[A-Z0-9]{10})\b/', strtoupper($text), $m)) {
            return $m[1];
        }
        if (preg_match('/\(([A-Z0-9]{6})\)/', strtoupper($text), $m)) {
            return $m[1];
        }
        if (preg_match('/\(([A-Z]{2,6}(?:\.[A-Z]{2})?)\)/', strtoupper($text), $m)) {
            return $m[1];
        }

        return '';
    }
}
