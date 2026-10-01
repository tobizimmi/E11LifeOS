<?php

namespace App\Http\Controllers;

use App\Models\HealthEntry;
use App\Models\HealthProfile;
use App\Support\PdfTheme;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Vaccination record of a person as PDF: every vaccination with its last date and when it is due
 * again, plus the check-ups – to take to the doctor or on a trip.
 */
class HealthPdfController extends Controller
{
    public function __invoke(Request $request, HealthProfile $profile): Response
    {
        abort_unless($profile->user_id === $request->user()->id, 404);

        $entries = HealthEntry::where('health_profile_id', $profile->id)->whereIn('kind', [HealthEntry::VACCINATION, HealthEntry::CHECKUP])->get()
            ->sortBy(fn (HealthEntry $entry) => mb_strtolower((string) $entry->title))->groupBy('kind');

        $html = view('health.vaccinations', [
            'profile' => $profile,
            'vaccinations' => $entries[HealthEntry::VACCINATION] ?? collect(),
            'checkups' => $entries[HealthEntry::CHECKUP] ?? collect(),
            'theme' => PdfTheme::for($request->user()->currentHousehold()?->id, 'general'),
        ])->render();

        return response(PdfTheme::render($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.(Str::slug(__('Vaccination record').' '.$profile->name, '-', 'de') ?: 'impfpass').'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
