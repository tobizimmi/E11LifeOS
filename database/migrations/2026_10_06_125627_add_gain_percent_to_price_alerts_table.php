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
        Schema::table('price_alerts', function (Blueprint $table) {
            // Set for one-click profit alerts (+5/10/20/30 % above the average buy price); null for manual alerts.
            $table->unsignedSmallInteger('gain_percent')->nullable()->after('target_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('price_alerts', function (Blueprint $table) {
            $table->dropColumn('gain_percent');
        });
    }
};
