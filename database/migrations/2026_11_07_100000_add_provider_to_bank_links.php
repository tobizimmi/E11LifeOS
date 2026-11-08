<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A bank link can now come from different PSD2 providers (GoCardless or Enable Banking).
     * accounts_meta keeps per-account IBAN/name (encrypted) so a sync needs no extra detail call
     * for providers that return it up front.
     */
    public function up(): void
    {
        Schema::table('bank_links', function (Blueprint $table) {
            $table->string('provider', 32)->default('gocardless')->after('household_id');
            $table->text('accounts_meta')->nullable()->after('account_ids');
        });
    }

    public function down(): void
    {
        Schema::table('bank_links', function (Blueprint $table) {
            $table->dropColumn(['provider', 'accounts_meta']);
        });
    }
};
