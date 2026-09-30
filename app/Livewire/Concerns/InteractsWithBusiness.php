<?php

namespace App\Livewire\Concerns;

use App\Models\BookkeepingEntry;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * Small business data is personal: every query is limited to the signed-in user in the
 * current household. Use together with InteractsWithHousehold.
 */
trait InteractsWithBusiness
{
    protected function businessProfile(): ?BusinessProfile
    {
        return BusinessProfile::where('user_id', $this->currentUser()->id)->first();
    }

    /**
     * @return Builder<Invoice>
     */
    protected function invoices(): Builder
    {
        return Invoice::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId);
    }

    /**
     * @return Builder<Customer>
     */
    protected function customers(): Builder
    {
        return Customer::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId);
    }

    /**
     * @return Builder<BookkeepingEntry>
     */
    protected function bookkeepingEntries(): Builder
    {
        return BookkeepingEntry::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId);
    }
}
