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
        // The area of the trip on the map (south, west, north, east) and when its places were loaded.
        Schema::table('trips', function (Blueprint $table) {
            $table->json('map_area')->nullable()->after('travel_mode');
            $table->timestamp('places_loaded_at')->nullable()->after('map_area');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['map_area', 'places_loaded_at']);
        });
    }
};
