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
        // How to get to this entry from the one before (foot, bike, car); empty = as set for the trip.
        Schema::table('trip_stops', function (Blueprint $table) {
            $table->string('travel_mode', 10)->nullable()->after('lng');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_stops', function (Blueprint $table) {
            $table->dropColumn('travel_mode');
        });
    }
};
