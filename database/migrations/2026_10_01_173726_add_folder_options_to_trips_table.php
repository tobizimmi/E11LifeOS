<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the trip folder shows besides the programme: the day plan, ideas, pages and web links.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->json('folder_options')->nullable()->after('group_info');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('folder_options');
        });
    }
};
