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
        Schema::table('households', function (Blueprint $table) {
            // Per-household on/off switches for optional modules, e.g. {"recipes": false}.
            // Missing keys fall back to the addon's default (see App\Support\Addons).
            $table->json('addons')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('addons');
        });
    }
};
