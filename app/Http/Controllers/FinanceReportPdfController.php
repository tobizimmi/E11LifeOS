<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Support\Finance\FinancePeriod;
use App\Support\Finance\FinanceReport;
use App\Support\PdfTheme;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Printable finance report of the signed-in user's own bookings.
 */
class FinanceReportPdfController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $household = $user->currentHousehold();
        abort_unless($household, 404);

        $period = (string) $request->query('period', FinancePeriod::DEFAULT);
        $period = array_key_exists($period, FinancePeriod::options()) ? $period : FinancePeriod::DEFAULT;

        $account = null;

        if ($request->filled('account')) {
            $account = BankAccount::where('user_id', $user->id)->where('household_id', $household->id)->find($request->integer('account')) ?? abort(404);
        }

        [$from, $to] = FinancePeriod::range($period);
        $report = FinanceReport::build(FinancePeriod::query($user, $household->id, $period, $account?->id)->get(), $from, $to);

        $html = view('finance.report-pdf', [
            'report' => $report,
            'periodLabel' => FinancePeriod::options()[$period],
            'account' => $account,
        ])->render();

        $pdf = PdfTheme::render($html);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="finanzbericht-'.$from->format('Y-m').'-'.$to->format('Y-m').'.pdf"',
        ]);
    }
}
