<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Transaction;
use App\Support\Finance\BankCsvImporter;
use App\Support\Finance\Categorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankImportHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_identical_bookings_without_a_reference_both_land_and_re_import_is_idempotent(): void
    {
        $account = BankAccount::factory()->create();
        $importer = new BankCsvImporter;
        $categorizer = new Categorizer($account->user_id);

        $bookings = [
            ['date' => '2026-06-01', 'amount' => -5.0, 'counterparty' => 'Bäcker', 'purpose' => 'Brötchen'],
            ['date' => '2026-06-01', 'amount' => -5.0, 'counterparty' => 'Bäcker', 'purpose' => 'Brötchen'],
        ];

        $first = $importer->importBookings($account, $bookings, $categorizer);
        $this->assertSame(2, $first['imported']);
        $this->assertSame(2, Transaction::where('bank_account_id', $account->id)->count());

        $second = $importer->importBookings($account, $bookings, $categorizer);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(2, $second['skipped']);
        $this->assertSame(2, Transaction::where('bank_account_id', $account->id)->count());
    }

    public function test_a_reference_separates_two_otherwise_identical_bookings(): void
    {
        $account = BankAccount::factory()->create();
        $importer = new BankCsvImporter;
        $categorizer = new Categorizer($account->user_id);

        $result = $importer->importBookings($account, [
            ['date' => '2026-06-02', 'amount' => -9.9, 'counterparty' => 'Shop', 'purpose' => 'Kauf', 'reference' => 'E2E-1'],
            ['date' => '2026-06-02', 'amount' => -9.9, 'counterparty' => 'Shop', 'purpose' => 'Kauf', 'reference' => 'E2E-2'],
        ], $categorizer);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['errors']);
    }
}
