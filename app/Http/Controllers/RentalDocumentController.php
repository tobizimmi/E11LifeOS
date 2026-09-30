<?php

namespace App\Http\Controllers;

use App\Models\RealEstate;
use App\Models\RealEstateApplicant;
use App\Models\RealEstateHandover;
use App\Models\RealEstateTenant;
use App\Models\RealEstateUtilityStatement;
use App\Support\RealEstate\RentalPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Letting PDFs for members who can see the property, and for tenants through their portal link.
 */
class RentalDocumentController extends Controller
{
    public function lease(Request $request, RealEstateTenant $tenant): Response
    {
        $this->authorizeFor($request, $tenant->real_estate_id);

        return $this->pdf(RentalPdf::lease($tenant), 'mietvertrag-'.Str::slug($tenant->name).'.pdf');
    }

    public function utilityStatement(Request $request, RealEstateUtilityStatement $statement, RealEstateTenant $tenant): Response
    {
        $this->authorizeFor($request, $statement->real_estate_id);
        abort_unless($tenant->real_estate_id === $statement->real_estate_id, 404);

        return $this->pdf(RentalPdf::utilityStatement($statement, $tenant), 'nebenkostenabrechnung-'.$statement->year.'-'.Str::slug($tenant->name).'.pdf');
    }

    public function applicant(Request $request, RealEstateApplicant $applicant): Response
    {
        $this->authorizeFor($request, $applicant->real_estate_id);

        return $this->pdf(RentalPdf::selfDisclosure($applicant->realEstate, $applicant), 'selbstauskunft-'.Str::slug($applicant->name).'.pdf');
    }

    public function blankSelfDisclosure(Request $request, RealEstate $realEstate): Response
    {
        $this->authorizeFor($request, $realEstate->id);

        return $this->pdf(RentalPdf::selfDisclosure($realEstate), 'selbstauskunft.pdf');
    }

    public function indexRent(Request $request, RealEstateTenant $tenant): Response
    {
        $this->authorizeFor($request, $tenant->real_estate_id);
        $validated = $request->validate(['index' => ['required', 'numeric', 'min:1', 'max:1000'], 'month' => ['nullable', 'date_format:Y-m']]);

        return $this->pdf(RentalPdf::indexRent($tenant, (float) $validated['index'], $validated['month'] ?? null), 'mieterhoehung-'.Str::slug($tenant->name).'.pdf');
    }

    public function dunning(Request $request, RealEstateTenant $tenant): Response
    {
        $this->authorizeFor($request, $tenant->real_estate_id);
        $validated = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'level' => ['nullable', 'integer', 'in:1,2']]);

        return $this->pdf(RentalPdf::dunning($tenant, (int) $validated['year'], (int) ($validated['level'] ?? 1)), 'zahlungserinnerung-'.Str::slug($tenant->name).'.pdf');
    }

    public function handover(Request $request, RealEstateHandover $handover): Response
    {
        $this->authorizeFor($request, $handover->real_estate_id);

        return $this->pdf(RentalPdf::handover($handover), 'uebergabeprotokoll-'.$handover->handed_over_on->format('Y-m-d').'.pdf');
    }

    public function handoverPhoto(Request $request, RealEstateHandover $handover, int $index): StreamedResponse
    {
        $this->authorizeFor($request, $handover->real_estate_id);
        $path = ($handover->photos ?? [])[$index] ?? abort(404);

        return Storage::disk(RealEstateHandover::DISK)->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    // ── Tenant portal (long random token instead of a login) ────────────

    public function portalLease(string $token): Response
    {
        $tenant = $this->portalTenant($token);

        return $this->pdf(RentalPdf::lease($tenant), 'mietvertrag.pdf');
    }

    public function portalStatement(string $token, RealEstateUtilityStatement $statement): Response
    {
        $tenant = $this->portalTenant($token);
        abort_unless($statement->real_estate_id === $tenant->real_estate_id && $statement->sent_on !== null, 404);

        return $this->pdf(RentalPdf::utilityStatement($statement, $tenant), 'nebenkostenabrechnung-'.$statement->year.'.pdf');
    }

    public function portalHandover(string $token, RealEstateHandover $handover): Response
    {
        $tenant = $this->portalTenant($token);
        abort_unless($handover->tenant_id === $tenant->id, 404);

        return $this->pdf(RentalPdf::handover($handover), 'uebergabeprotokoll-'.$handover->handed_over_on->format('Y-m-d').'.pdf');
    }

    private function portalTenant(string $token): RealEstateTenant
    {
        return (strlen($token) >= 32 ? RealEstateTenant::whereShareToken('portal_token', $token)->first() : null) ?? abort(404);
    }

    private function authorizeFor(Request $request, int $realEstateId): void
    {
        $user = $request->user();

        abort_unless(RealEstate::query()->visibleTo($user, (int) $user->currentHousehold()?->id)->whereKey($realEstateId)->exists(), 404);
    }

    private function pdf(string $content, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
