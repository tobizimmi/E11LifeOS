<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the member's finance categories count in the cashflow analysis (salary, expense, passive …).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('cashflow_categories')->nullable()->after('cashflow_manual_only');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cashflow_categories');
        });
    }
};
