<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bank_depot_positions', function (Blueprint $table) {
            // Depot account the holding belongs to (one login can have several depots).
            $table->string('account_number', 34)->nullable()->after('bank_connection_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_depot_positions', function (Blueprint $table) {
            $table->dropColumn('account_number');
        });
    }
};
