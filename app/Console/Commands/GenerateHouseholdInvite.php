<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\HouseholdInvite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:generate-household-invite {--household= : Household ID to invite into; omit for a "new household" invite} {--expires-days= : Days until the code expires}')]
#[Description('Generate an invite code for registration (new household, or joining an existing one)')]
class GenerateHouseholdInvite extends Command
{
    public function handle(): int
    {
        $householdId = $this->option('household');

        if ($householdId !== null && ! Household::whereKey($householdId)->exists()) {
            $this->error("Household #{$householdId} not found.");

            return self::FAILURE;
        }

        $expiresDays = $this->option('expires-days');

        $invite = HouseholdInvite::create([
            'household_id' => $householdId,
            'code' => HouseholdInvite::generateCode(),
            'created_by' => null,
            'expires_at' => $expiresDays !== null ? now()->addDays((int) $expiresDays) : null,
        ]);

        $this->info("Invite code: {$invite->code}");
        $this->line($householdId !== null
            ? "Joins household #{$householdId}."
            : 'Creates a new household on registration.');

        return self::SUCCESS;
    }
}
