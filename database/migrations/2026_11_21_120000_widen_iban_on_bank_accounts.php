<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The iban column doubles as the stable per-account identifier for PSD2 links (Enable Banking /
     * GoCardless): when a granted account has no IBAN, the provider's account id is stored here
     * instead. Those ids can be longer than a 34-char IBAN, so the column is widened to fit.
     */
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('iban', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('iban', 34)->nullable()->change();
        });
    }
};
