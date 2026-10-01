<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Places of the trip maps were loaded with their local names (e.g. Cyrillic); they load again
     * in the app language the next time a map is opened.
     */
    public function up(): void
    {
        DB::table('trips')->whereNotNull('places_loaded_at')->update(['places_loaded_at' => null]);
    }

    public function down(): void
    {
        //
    }
};
