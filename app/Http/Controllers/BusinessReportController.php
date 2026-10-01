<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Support\Business\BookkeepingCategories;
use App\Support\Business\EuerReport;
use App\Support\PdfTheme;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The yearly profit calculation (EÜR) as PDF or all bookings of the year as CSV.
 */
class BusinessReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'format' => ['required', 'in:pdf,csv'],
        ]);

        $user = $request->user();
        $household = $user->currentHousehold();
        abort_unless($household, 404);

        $profile = BusinessProfile::where('user_id', $user->id)->first();
        $report = EuerReport::build($user, $household->id, (int) $validated['year'], $profile);

        if ($validated['format'] === 'csv') {
            return response(EuerReport::csv($report), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="buchungen-'.$report['year'].'.csv"',
            ]);
        }

        $html = view('business.report-pdf', [
            'report' => $report,
            'profile' => $profile,
            'lineDefinitions' => BookkeepingCategories::euerLines(),
        ])->render();

        $pdf = PdfTheme::render($html);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="euer-'.$report['year'].'.pdf"',
        ]);
    }
}
