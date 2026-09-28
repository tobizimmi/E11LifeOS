<?php

namespace App\Http\Controllers;

use App\Models\RealEstate;
use App\Models\RealEstateApplicant;
use App\Models\RealEstateTenant;
use App\Models\RealEstateUtilityStatement;
use App\Support\RealEstate\RentalPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Lease, utility statement and self-disclosure PDFs for members who can see the property.
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
