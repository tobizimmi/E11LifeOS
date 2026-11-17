<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BankAccount;
use App\Support\Csv;
use App\Support\Finance\BankCsvImporter;
use App\Support\Finance\Categorizer;
use App\Support\Finance\FinancePeriod;
use App\Support\Finance\FinanceReport;
use App\Support\Finance\TaxCategories;
use App\Support\Finance\TaxYear;
use App\Support\PdfTheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files of the finances for the app: bank statement import (CSV) and the tax CSV / report PDF.
 */
class FinanceFilesController extends ApiController
{
    public function taxExport(int $year): StreamedResponse
    {
        abort_unless($year >= 2000 && $year <= 2100, 404);

        $user = $this->user();
        $bookings = TaxYear::bookings($user, (int) $user->currentHousehold()?->id, $year);
        $categories = TaxCategories::all();

        return response()->streamDownload(function () use ($bookings, $categories) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Date'), __('Tax category'), __('Form'), __('Payee'), __('Purpose'), __('Amount'), __('Tax-relevant amount')], ';');

            foreach ($bookings as $transaction) {
                fputcsv($out, [
                    $transaction->booked_on->format('d.m.Y'),
                    $categories[$transaction->tax_category]['label'] ?? $transaction->tax_category,
                    $categories[$transaction->tax_category]['form'] ?? '',
                    Csv::sanitizeCell($transaction->counterparty),
                    Csv::sanitizeCell($transaction->purpose),
                    number_format($transaction->amount, 2, ',', ''),
                    number_format(TaxYear::relevantAmount($transaction), 2, ',', ''),
                ], ';');
            }

            fclose($out);
        }, 'steuer-'.$year.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function reportPdf(Request $request): Response
    {
        $user = $this->user();
        $household = $user->currentHousehold() ?? abort(404);

        $period = (string) $request->query('period', FinancePeriod::DEFAULT);
        $period = array_key_exists($period, FinancePeriod::options()) ? $period : FinancePeriod::DEFAULT;

        [$from, $to] = FinancePeriod::range($period);
        $report = FinanceReport::build(FinancePeriod::query($user, $household->id, $period, null)->get(), $from, $to);

        $html = view('finance.report-pdf', [
            'report' => $report,
            'periodLabel' => FinancePeriod::options()[$period],
            'account' => null,
        ])->render();

        return response(PdfTheme::render($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="finanzbericht-'.$from->format('Y-m').'-'.$to->format('Y-m').'.pdf"',
        ]);
    }

    /**
     * What the file contains (format, account names) before importing it.
     */
    public function inspect(Request $request, BankCsvImporter $importer): JsonResponse
    {
        $request->validate(['statement' => ['required', 'file', 'max:10240', 'mimes:csv,txt']]);

        try {
            return $this->ok($importer->inspect((string) file_get_contents($request->file('statement')->getRealPath())));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['statement' => $exception->getMessage()]);
        }
    }

    /**
     * Imports the statement into an account: `account` is an own account id, "new" (named by `name`)
     * or "auto" (the file has an account column).
     */
    public function import(Request $request, BankCsvImporter $importer): JsonResponse
    {
        $user = $this->user();
        $household = $user->currentHousehold() ?? abort(404);
        $data = $request->validate([
            'statement' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'account' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);
        $contents = (string) file_get_contents($request->file('statement')->getRealPath());
        $categorizer = new Categorizer($user->id);

        try {
            if ($data['account'] === 'auto') {
                return $this->ok($importer->importByAccountColumn($user->id, $household->id, $contents, $categorizer));
            }

            if ($data['account'] === 'new') {
                $name = trim((string) ($data['name'] ?? ''));
                if ($name === '') {
                    throw ValidationException::withMessages(['name' => __('Please name the new account.')]);
                }
                $account = BankAccount::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => $name]);
            } else {
                $account = BankAccount::where('user_id', $user->id)->where('household_id', $household->id)->findOrFail((int) $data['account']);
            }

            try {
                return $this->ok($importer->import($account, $contents, $categorizer) + ['account_id' => $account->id]);
            } catch (RuntimeException $exception) {
                if ($data['account'] === 'new' && $account->transactions()->doesntExist()) {
                    $account->delete();
                }
                throw $exception;
            }
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['statement' => $exception->getMessage()]);
        }
    }
}
