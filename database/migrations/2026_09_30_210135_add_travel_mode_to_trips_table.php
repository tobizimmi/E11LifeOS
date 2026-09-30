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
        // How the travellers get around on the day routes (foot, bike, car).
        Schema::table('trips', function (Blueprint $table) {
            $table->string('travel_mode', 10)->default('foot')->after('color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('travel_mode');
        });
    }
};
