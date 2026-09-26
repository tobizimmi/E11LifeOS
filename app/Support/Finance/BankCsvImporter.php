<?php

namespace App\Support\Finance;

use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Reads CSV exports of German banks (Sparkasse CSV-CAMT, ING, DKB, comdirect, N26,
 * Volksbank/VR, Postbank, Consorsbank …): finds the header row, recognises the columns by
 * their names, converts German number/date formats and skips bookings already imported.
 */
class BankCsvImporter
{
    private const MAX_ROWS = 20000;

    /**
     * Column name fragments (lower case) per field, most specific first.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMNS = [
        'date' => ['buchungstag', 'buchungsdatum', 'booking date', 'buchung', 'datum', 'date', 'valuta', 'wertstellung'],
        'amount' => ['betrag (€)', 'betrag (eur)', 'amount (eur)', 'betrag', 'umsatz in eur', 'umsatz', 'amount'],
        'debit' => ['soll', 'debit', 'belastung', 'ausgang'],
        'credit' => ['haben', 'credit', 'gutschrift', 'eingang'],
        'payee' => ['zahlungsempfänger', 'zahlungsempfaenger', 'empfänger', 'empfaenger', 'payee', 'beguenstigter', 'begünstigter'],
        'payer' => ['zahlungspflichtige', 'auftraggeber', 'zahlungspflichtiger'],
        'counterparty' => ['beguenstigter/zahlungspflichtiger', 'auftraggeber/empfänger', 'auftraggeber / begünstigter', 'name', 'gegenkonto name', 'partner name'],
        'purpose' => ['verwendungszweck', 'payment reference', 'buchungstext', 'purpose', 'reference', 'beschreibung', 'vorgang', 'umsatzart'],
    ];

    /**
     * @return array{imported: int, skipped: int, errors: int}
     */
    public function import(BankAccount $account, string $contents, Categorizer $categorizer): array
    {
        $rows = $this->rows($contents);
        [$headerIndex, $columns] = $this->findHeader($rows);
        $result = ['imported' => 0, 'skipped' => 0, 'errors' => 0];

        foreach (array_slice($rows, $headerIndex + 1, self::MAX_ROWS) as $row) {
            if (count(array_filter($row, fn ($cell) => trim((string) $cell) !== '')) < 2) {
                continue;
            }

            $booking = $this->booking($row, $columns);

            if ($booking === null) {
                $result['errors']++;

                continue;
            }

            $hash = sha1(implode('|', [$booking['date'], number_format($booking['amount'], 2, '.', ''), mb_strtolower($booking['counterparty'] ?? ''), mb_strtolower($booking['purpose'] ?? '')]));

            $transaction = Transaction::firstOrNew(['bank_account_id' => $account->id, 'import_hash' => $hash]);

            if ($transaction->exists) {
                $result['skipped']++;

                continue;
            }

            $transaction->fill([
                'household_id' => $account->household_id,
                'user_id' => $account->user_id,
                'booked_on' => $booking['date'],
                'amount' => $booking['amount'],
                'counterparty' => $booking['counterparty'] !== null ? mb_substr($booking['counterparty'], 0, 255) : null,
                'purpose' => $booking['purpose'] !== null ? mb_substr($booking['purpose'], 0, 2000) : null,
                'category' => $categorizer->categorize($booking['counterparty'], $booking['purpose'], $booking['amount']),
            ])->save();

            $result['imported']++;
        }

        return $result;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function rows(string $contents): array
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $lines = preg_split('/\r\n|\r|\n/', trim($contents));
        $sample = implode("\n", array_slice($lines, 0, 30));
        $delimiter = collect([';', ',', "\t"])->sortByDesc(fn (string $candidate) => substr_count($sample, $candidate))->first();

        return array_map(fn (string $line) => array_map(fn ($cell) => trim((string) $cell), str_getcsv($line, $delimiter, '"', '')), $lines);
    }

    /**
     * The first row that names a date column and an amount (or debit/credit) column.
     *
     * @param  array<int, array<int, string>>  $rows
     * @return array{0: int, 1: array<string, int>}
     */
    private function findHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 40, true) as $index => $row) {
            $columns = $this->mapColumns($row);

            if (isset($columns['date']) && (isset($columns['amount']) || isset($columns['debit']) || isset($columns['credit']))) {
                return [$index, $columns];
            }
        }

        throw new RuntimeException(__('The file does not look like a bank statement (no date and amount columns found).'));
    }

    /**
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function mapColumns(array $header): array
    {
        $names = array_map(fn ($name) => mb_strtolower(trim((string) $name, " \t\"'")), $header);
        $columns = [];
        $used = [];

        foreach (self::COLUMNS as $field => $fragments) {
            foreach ($fragments as $fragment) {
                // An exact column name beats one that merely contains the fragment.
                foreach ([true, false] as $exact) {
                    foreach ($names as $index => $name) {
                        if (in_array($index, $used, true) || $name === '' || ($exact ? $name !== $fragment : ! str_contains($name, $fragment))) {
                            continue;
                        }

                        // "Saldo", "Währung" or an original amount never count as the amount.
                        if (in_array($field, ['amount', 'debit', 'credit'], true) && preg_match('/saldo|währung|waehrung|currency|kurs|ursprung|foreign|fremdwährung|anzeige/', $name)) {
                            continue;
                        }

                        $columns[$field] = $index;
                        $used[] = $index;

                        continue 4;
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     * @return array{date: string, amount: float, counterparty: ?string, purpose: ?string}|null
     */
    private function booking(array $row, array $columns): ?array
    {
        $cell = fn (string $field) => isset($columns[$field]) ? trim((string) ($row[$columns[$field]] ?? '')) : '';

        $date = self::parseDate($cell('date'));

        if (isset($columns['amount'])) {
            $amount = self::parseAmount($cell('amount'));
        } else {
            $debit = self::parseAmount($cell('debit'));
            $credit = self::parseAmount($cell('credit'));
            $amount = $debit === null && $credit === null ? null : (float) ($credit ?? 0) - abs((float) ($debit ?? 0));
        }

        if ($date === null || $amount === null) {
            return null;
        }

        // DKB & Co.: separate payer and payee columns – the other side depends on the direction.
        $counterparty = $cell('counterparty') ?: ($amount < 0 ? ($cell('payee') ?: $cell('payer')) : ($cell('payer') ?: $cell('payee')));

        return [
            'date' => $date,
            'amount' => round($amount, 2),
            'counterparty' => $counterparty !== '' ? preg_replace('/\s+/u', ' ', $counterparty) : null,
            'purpose' => $cell('purpose') !== '' ? preg_replace('/\s+/u', ' ', $cell('purpose')) : null,
        ];
    }

    /**
     * "1.234,56", "-12,30", "12.30", "1,234.56", "12,30 €", "+5,00".
     */
    public static function parseAmount(string $value): ?float
    {
        $value = str_replace(["\u{00A0}", ' ', '€', 'EUR'], '', trim($value));

        if ($value === '' || ! preg_match('/\d/', $value)) {
            return null;
        }

        $negative = str_starts_with($value, '-') || str_ends_with($value, '-') || str_starts_with($value, '(');
        $value = trim($value, '+-() ');

        if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $value) || preg_match('/^\d+,\d+$/', $value)) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (preg_match('/^\d{1,3}(,\d{3})*\.\d+$/', $value)) {
            $value = str_replace(',', '', $value);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            $value = str_replace('.', '', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return ($negative ? -1 : 1) * (float) $value;
    }

    /**
     * "24.09.2026", "24.09.26", "2026-09-24", "24/09/2026".
     */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);

        foreach (['d.m.Y' => '/^\d{2}\.\d{2}\.\d{4}/', 'd.m.y' => '/^\d{2}\.\d{2}\.\d{2}(?!\d)/', 'Y-m-d' => '/^\d{4}-\d{2}-\d{2}/', 'd/m/Y' => '/^\d{2}\/\d{2}\/\d{4}/'] as $format => $pattern) {
            if (preg_match($pattern, $value, $match)) {
                try {
                    return Carbon::createFromFormat('!'.$format, $match[0])->toDateString();
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }
}
