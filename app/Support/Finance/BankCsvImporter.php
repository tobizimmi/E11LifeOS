<?php

namespace App\Support\Finance;

use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Reads CSV exports of German banks (Sparkasse CSV-CAMT, ING, DKB, comdirect, N26,
 * Volksbank/VR, Postbank, Consorsbank …) and banking apps (Outbank, with several accounts and
 * categories in one file): finds the header row, recognises the columns by their names,
 * converts German number/date formats and skips bookings already imported.
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
        'purpose' => ['verwendungszweck', 'payment reference', 'buchungstext', 'purpose', 'reason', 'reference', 'beschreibung', 'vorgang', 'umsatzart'],
        'category_path' => ['kategorie-pfad', 'category-path', 'category path', 'kategoriepfad'],
        'category' => ['kategorie', 'category', 'hauptkategorie'],
        'subcategory' => ['unterkategorie', 'subcategory', 'sub-category'],
    ];

    /**
     * Exact names of a column holding the own account (banking apps export several accounts at once).
     */
    private const ACCOUNT_COLUMNS = ['konto', 'account', 'kontoname', 'account name', 'konto-name', 'bankkonto'];

    /**
     * @return array{imported: int, skipped: int, errors: int}
     */
    public function import(BankAccount $account, string $contents, Categorizer $categorizer): array
    {
        $rows = $this->rows($contents);
        [$headerIndex, $columns] = $this->findHeader($rows);

        return $this->importRows(array_slice($rows, $headerIndex + 1, self::MAX_ROWS), $columns, fn () => $account, $categorizer);
    }

    /**
     * Imports a file with an account column (e.g. an Outbank export of several accounts): the
     * bookings go to the owner's account of the same name, which is created when missing.
     *
     * @return array{imported: int, skipped: int, errors: int, accounts: array<int, string>}
     */
    public function importByAccountColumn(int $userId, int $householdId, string $contents, Categorizer $categorizer): array
    {
        $rows = $this->rows($contents);
        [$headerIndex, $columns] = $this->findHeader($rows);

        if (! isset($columns['account'])) {
            throw new RuntimeException(__('The file has no account column – please choose the account to import into.'));
        }

        $accounts = [];
        $resolve = function (array $row) use (&$accounts, $columns, $userId, $householdId): BankAccount {
            $name = mb_substr(trim((string) ($row[$columns['account']] ?? '')), 0, 100) ?: __('Imported account');

            return $accounts[$name] ??= BankAccount::firstOrCreate(['user_id' => $userId, 'household_id' => $householdId, 'name' => $name]);
        };

        $result = $this->importRows(array_slice($rows, $headerIndex + 1, self::MAX_ROWS), $columns, $resolve, $categorizer);

        return $result + ['accounts' => array_keys($accounts)];
    }

    /**
     * What a file contains before importing it: the recognised format and the accounts named in it.
     *
     * @return array{format: string, accounts: array<int, string>}
     */
    public function inspect(string $contents): array
    {
        $rows = $this->rows($contents);
        [$headerIndex, $columns] = $this->findHeader($rows);
        $accounts = isset($columns['account'])
            ? collect(array_slice($rows, $headerIndex + 1, self::MAX_ROWS))->map(fn (array $row) => trim((string) ($row[$columns['account']] ?? '')))->filter()->unique()->values()->all()
            : [];
        $isOutbank = isset($columns['category_path']) || (isset($columns['account'], $columns['category']));

        return ['format' => $isOutbank ? 'outbank' : 'bank', 'accounts' => $accounts];
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @param  array<string, int>  $columns
     * @param  callable(array<int, string>): BankAccount  $accountFor
     * @return array{imported: int, skipped: int, errors: int}
     */
    private function importRows(array $rows, array $columns, callable $accountFor, Categorizer $categorizer): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        $seen = [];

        foreach ($rows as $row) {
            if (count(array_filter($row, fn ($cell) => trim((string) $cell) !== '')) < 2) {
                continue;
            }

            $booking = $this->booking($row, $columns);

            if ($booking === null) {
                $result['errors']++;

                continue;
            }

            $account = $accountFor($row);

            $base = [$booking['date'], number_format($booking['amount'], 2, '.', ''), mb_strtolower($booking['counterparty'] ?? ''), mb_strtolower($booking['purpose'] ?? '')];
            $hash = sha1(implode('|', $base));

            // A CSV has no bank reference to tell two real bookings with the same date, amount,
            // counterparty and purpose apart – so only a duplicate hash WITHIN this import gets a
            // running number, baked into the hash INPUT (not appended to the output: import_hash
            // is a fixed-width char(40) column, and sha1(...).'#2' would overflow it on MySQL).
            // The first occurrence keeps matching previously imported files.
            $seen[$hash] = ($seen[$hash] ?? 0) + 1;

            if ($seen[$hash] > 1) {
                $hash = sha1(implode('|', [...$base, $seen[$hash]]));
            }

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
                'category' => $categorizer->categorize($booking['counterparty'], $booking['purpose'], $booking['amount'], $booking['source_category']),
            ])->save();

            $result['imported']++;
        }

        return $result;
    }

    /**
     * Bookings from another source (e.g. FinTS) with the same duplicate check as the CSV import.
     *
     * @param  list<array{date: string, amount: float, counterparty: ?string, purpose: ?string, reference?: string}>  $bookings
     * @return array{imported: int, skipped: int, errors: int, transactions: list<Transaction>}
     */
    public function importBookings(BankAccount $account, array $bookings, Categorizer $categorizer): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'transactions' => []];
        $seen = [];

        foreach ($bookings as $booking) {
            try {
                $base = [$booking['date'], number_format($booking['amount'], 2, '.', ''), mb_strtolower($booking['counterparty'] ?? ''), mb_strtolower($booking['purpose'] ?? '')];

                // FinTS carries an end-to-end reference, so two otherwise identical bookings (same
                // date, amount, counterparty and purpose) still get different hashes with it.
                $reference = (string) ($booking['reference'] ?? '');

                // Without a reference the bank gives us nothing to tell two real same-day bookings of
                // the same amount apart, so a running number within this batch keeps the second one
                // from collapsing into the first (same guard as the CSV import). Baked into the hash
                // INPUT, not appended to the output (import_hash is a fixed-width char(40) column).
                $signature = implode('|', [...$base, $reference]);
                $seen[$signature] = ($seen[$signature] ?? 0) + 1;
                $hash = $reference !== '' || $seen[$signature] === 1
                    ? sha1($signature)
                    : sha1(implode('|', [...$base, $reference, $seen[$signature]]));

                // Transactions imported before the reference was added to the hash are stored under
                // the OLD (shorter) hash — check that first, or every one of them would re-import as
                // "new" the next time this account syncs. Only once neither hash matches is this
                // genuinely a new booking, stored from now on under the new, more precise hash.
                $legacyHash = $seen[$signature] === 1 ? sha1(implode('|', $base)) : null;
                $transaction = ($legacyHash !== null ? Transaction::where('bank_account_id', $account->id)->where('import_hash', $legacyHash)->first() : null)
                    ?? Transaction::firstOrNew(['bank_account_id' => $account->id, 'import_hash' => $hash]);

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
                    'category' => $categorizer->categorize($booking['counterparty'], $booking['purpose'], $booking['amount'], null),
                ])->save();

                $result['imported']++;
                $result['transactions'][] = $transaction;
            } catch (Throwable $exception) {
                // One malformed booking must not abort the whole account's sync.
                report($exception);
                $result['errors']++;
            }
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

        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        // Normalise line endings so a quoted field that spans several lines survives the parser.
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        $sample = implode("\n", array_slice(explode("\n", $contents), 0, 30));
        $delimiter = collect([';', ',', "\t", '|'])->sortByDesc(fn (string $candidate) => substr_count($sample, $candidate))->first() ?? ';';

        // Stream through fgetcsv rather than splitting on newlines first: that way a quoted
        // "Verwendungszweck" containing a line break or the delimiter stays in one cell.
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            // A blank line comes back as [null]; keep it as an empty row so the header scan and the
            // "skip near-empty rows" guard behave as before.
            $rows[] = $row === [null] ? [''] : array_map(fn ($cell) => trim((string) $cell), $row);

            if (count($rows) > self::MAX_ROWS + 50) {
                break;
            }
        }

        fclose($stream);

        return $rows;
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

        foreach ($names as $index => $name) {
            if (in_array($name, self::ACCOUNT_COLUMNS, true)) {
                $columns['account'] = $index;
                $used[] = $index;

                break;
            }
        }

        foreach (self::COLUMNS as $field => $fragments) {
            // An exact column name (for any fragment) beats one that merely contains a fragment.
            foreach ([true, false] as $exact) {
                foreach ($fragments as $fragment) {
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
     * @return array{date: string, amount: float, counterparty: ?string, purpose: ?string, source_category: ?string}|null
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
            'source_category' => ($cell('category_path') ?: trim($cell('category').' / '.$cell('subcategory'), ' /')) ?: null,
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
     * "24.09.2026", "24.09.26", "2026-09-24", "2026-09-24T10:00", "24/09/2026", "24-09-2026",
     * "2026/09/24". A trailing time is ignored (the patterns match only the leading date).
     */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);

        foreach ([
            'd.m.Y' => '/^\d{2}\.\d{2}\.\d{4}/',
            'd.m.y' => '/^\d{2}\.\d{2}\.\d{2}(?!\d)/',
            'Y-m-d' => '/^\d{4}-\d{2}-\d{2}/',
            'Y/m/d' => '/^\d{4}\/\d{2}\/\d{2}/',
            'd/m/Y' => '/^\d{2}\/\d{2}\/\d{4}/',
            'd-m-Y' => '/^\d{2}-\d{2}-\d{4}/',
            'd-m-y' => '/^\d{2}-\d{2}-\d{2}(?!\d)/',
        ] as $format => $pattern) {
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
