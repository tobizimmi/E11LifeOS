<?php

namespace App\Support\RealEstate;

use App\Models\RealEstate;
use App\Models\RealEstateApplicant;
use App\Models\RealEstateTenant;
use App\Models\RealEstateUtilityStatement;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDFs for letting: residential lease (draft to review), utility statement per tenant and the
 * self-disclosure of an applicant (filled in or blank for viewings).
 */
class RentalPdf
{
    public static function lease(RealEstateTenant $tenant): string
    {
        return self::render(view('real-estate.pdf.lease', [
            'tenant' => $tenant,
            'realEstate' => $tenant->realEstate,
        ])->render());
    }

    public static function utilityStatement(RealEstateUtilityStatement $statement, RealEstateTenant $tenant): string
    {
        $result = RentalAccounting::utilityStatement($statement);
        $row = collect($result['tenants'])->first(fn (array $row) => $row['tenant']->id === $tenant->id);
        abort_if($row === null, 404);

        return self::render(view('real-estate.pdf.utility-statement', [
            'statement' => $statement,
            'realEstate' => $statement->realEstate,
            'row' => $row,
            'periodDays' => $result['period_days'],
        ])->render());
    }

    public static function selfDisclosure(RealEstate $realEstate, ?RealEstateApplicant $applicant = null): string
    {
        return self::render(view('real-estate.pdf.self-disclosure', [
            'realEstate' => $realEstate,
            'applicant' => $applicant,
        ])->render());
    }

    private static function render(string $html): string
    {
        $dompdf = new Dompdf((new Options)->setIsRemoteEnabled(false)->setDefaultFont('DejaVu Sans'));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
