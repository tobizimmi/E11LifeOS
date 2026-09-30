<?php

namespace App\Support\Portfolio\Import;

abstract class AbstractParser
{
    protected array $errors = [];

    abstract public function parse(string $csvContent): array;

    protected function parseCsvLines(string $content, string $separator = ';', bool $stripBom = true): array
    {
        if ($stripBom) {
            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        }
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);

        $lines = array_filter(explode("\n", $content), fn ($l) => trim($l) !== '');
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = str_getcsv($line, $separator, '"', '');
        }

        return $rows;
    }

    protected function parseGermanDate(string $date): ?string
    {
        // DD.MM.YYYY -> YYYY-MM-DD
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim($date), $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        // YYYY-MM-DD bereits korrekt
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date))) {
            return trim($date);
        }
        // DD.MM.YY
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2})$/', trim($date), $m)) {
            $year = (int) $m[3] > 50 ? '19'.$m[3] : '20'.$m[3];

            return sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]);
        }

        return null;
    }

    protected function parseAmount(string $value): float
    {
        $v = trim($value);
        $v = str_replace([' ', "\xc2\xa0"], '', $v); // Non-breaking space
        $v = preg_replace('/[^\d,.\-]/', '', $v);

        // Deutsches Format: 1.234,56 -> 1234.56
        if (preg_match('/\d{1,3}(\.\d{3})*,\d+$/', $v)) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif (strpos($v, ',') !== false) {
            $v = str_replace(',', '.', $v);
        }

        return (float) $v;
    }

    protected function result(array $transactions): array
    {
        return [
            'transactions' => $transactions,
            'errors' => $this->errors,
            'count' => count($transactions),
        ];
    }
}
