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
        // "attributes" collides with Eloquent's own $attributes property.
        Schema::table('smart_home_entities', function (Blueprint $table) {
            $table->renameColumn('attributes', 'ha_attributes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('smart_home_entities', function (Blueprint $table) {
            $table->renameColumn('ha_attributes', 'attributes');
        });
    }
};
