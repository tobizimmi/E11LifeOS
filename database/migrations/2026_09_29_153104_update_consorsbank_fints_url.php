<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Consorsbank answers FinTS only at its brokerage address; saved connections move there.
     */
    public function up(): void
    {
        DB::table('bank_connections')->where('fints_url', 'https://fints.consorsbank.de/fints')
            ->update(['fints_url' => 'https://brokerage-hbci.consorsbank.de/hbci']);
    }

    public function down(): void
    {
        DB::table('bank_connections')->where('fints_url', 'https://brokerage-hbci.consorsbank.de/hbci')
            ->update(['fints_url' => 'https://fints.consorsbank.de/fints']);
    }
};
