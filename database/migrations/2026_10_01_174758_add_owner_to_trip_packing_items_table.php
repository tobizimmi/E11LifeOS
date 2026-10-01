<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Items of a member's personal packing list (only they see them); null = the shared list.
     */
    public function up(): void
    {
        Schema::table('trip_packing_items', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('trip_id')->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trip_packing_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
