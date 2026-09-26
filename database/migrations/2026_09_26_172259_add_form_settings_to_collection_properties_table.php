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
        Schema::table('collection_properties', function (Blueprint $table) {
            // Form builder: mandatory fields, prefilled values and a hint below the field.
            $table->boolean('required')->default(false)->after('position');
            $table->json('default_value')->nullable()->after('required');
            $table->string('help')->nullable()->after('default_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collection_properties', function (Blueprint $table) {
            $table->dropColumn(['required', 'default_value', 'help']);
        });
    }
};
