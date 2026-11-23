<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_positions', function (Blueprint $table) {
            // A later import changed the quantity, so the hand-entered purchase price should be
            // checked and, if needed, re-entered (the real average buy price has likely changed).
            $table->boolean('cost_basis_override_stale')->default(false)->after('cost_basis_estimated');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_positions', function (Blueprint $table) {
            $table->dropColumn('cost_basis_override_stale');
        });
    }
};
