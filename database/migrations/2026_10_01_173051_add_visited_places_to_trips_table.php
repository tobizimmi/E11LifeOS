<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Places of the trip area that were visited (OpenStreetMap ids; kept when the places load again).
     * The places load once more to get menus, pictures and phone numbers.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->json('visited_places')->nullable()->after('places_loaded_at');
        });

        DB::table('trips')->whereNotNull('places_loaded_at')->update(['places_loaded_at' => null]);
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('visited_places');
        });
    }
};
