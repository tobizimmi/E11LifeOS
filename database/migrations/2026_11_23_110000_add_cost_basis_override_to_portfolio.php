<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_positions', function (Blueprint $table) {
            // A purchase price the member entered by hand; overrides the calculated average and is
            // kept when positions are recalculated (used when an import delivered no reliable one).
            $table->decimal('cost_basis_override', 15, 4)->nullable()->after('avg_buy_price');
            // The calculated purchase price is only an estimate (an import had no reliable one).
            $table->boolean('cost_basis_estimated')->default(false)->after('cost_basis_override');
        });

        Schema::table('portfolio_transactions', function (Blueprint $table) {
            // The price is not the real acquisition price but the current price, because the bank
            // delivered no (plausible) one on import.
            $table->boolean('cost_estimated')->default(false)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_positions', function (Blueprint $table) {
            $table->dropColumn(['cost_basis_override', 'cost_basis_estimated']);
        });

        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->dropColumn('cost_estimated');
        });
    }
};
