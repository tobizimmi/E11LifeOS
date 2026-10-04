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
        Schema::table('smart_home_entities', function (Blueprint $table) {
            $table->foreignId('meter_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('favorite')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('smart_home_entities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meter_id');
            $table->dropColumn('favorite');
        });
    }
};
