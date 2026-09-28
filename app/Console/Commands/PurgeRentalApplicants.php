<?php

namespace App\Console\Commands;

use App\Models\RealEstateApplicant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('real-estate:purge-applicants')]
#[Description('Delete rejected rental applicants (and their self-disclosure) after the retention period')]
class PurgeRentalApplicants extends Command
{
    public function handle(): int
    {
        $deleted = RealEstateApplicant::where('status', 'rejected')
            ->where('updated_at', '<', now()->subDays(RealEstateApplicant::RETENTION_DAYS))
            ->delete();

        $this->info("{$deleted} rejected applicant(s) deleted.");

        return self::SUCCESS;
    }
}
