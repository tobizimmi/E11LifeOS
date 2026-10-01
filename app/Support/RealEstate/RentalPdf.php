<?php

namespace App\Support\RealEstate;

use App\Models\RealEstate;
use App\Models\RealEstateApplicant;
use App\Models\RealEstateHandover;
use App\Models\RealEstateTenant;
use App\Models\RealEstateUtilityStatement;
use App\Support\PdfTheme;

/**
 * PDFs for letting: residential lease (draft to review), utility statement per tenant, the
 * self-disclosure of an applicant (filled in or blank for viewings), index rent increase,
 * payment reminder and handover protocol.
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

    /**
     * Rent increase letter for an index rent with the new cold rent and when it applies.
     */
    public static function indexRent(RealEstateTenant $tenant, float $currentIndex, ?string $currentMonth): string
    {
        $calculation = RentalAccounting::indexRent($tenant, $currentIndex);
        abort_if($calculation === null, 404);

        return self::render(view('real-estate.pdf.index-rent', [
            'tenant' => $tenant,
            'realEstate' => $tenant->realEstate,
            'calculation' => $calculation,
            'currentIndex' => $currentIndex,
            'currentMonth' => $currentMonth,
        ])->render());
    }

    /**
     * Payment reminder (level 1) or dunning letter (level 2) for the months still open in a year.
     */
    public static function dunning(RealEstateTenant $tenant, int $year, int $level): string
    {
        $months = RentalAccounting::openMonths($tenant, $year);
        abort_if($months === [], 404);

        return self::render(view('real-estate.pdf.dunning', [
            'tenant' => $tenant,
            'realEstate' => $tenant->realEstate,
            'months' => $months,
            'year' => $year,
            'level' => $level,
            'deadline' => today()->addDays(14),
        ])->render());
    }

    public static function handover(RealEstateHandover $handover): string
    {
        return self::render(view('real-estate.pdf.handover', [
            'handover' => $handover,
            'realEstate' => $handover->realEstate,
            'photos' => $handover->photoDataUris(),
        ])->render());
    }

    private static function render(string $html): string
    {
        $pdf = PdfTheme::render($html, footer: false);

        return $pdf;
    }
}
