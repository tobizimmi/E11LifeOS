<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Households created through open self-registration wait for an administrator to approve them.
     * Every household that already exists is treated as approved.
     */
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('name');
        });

        DB::table('households')->whereNull('approved_at')->update(['approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
    }
};
