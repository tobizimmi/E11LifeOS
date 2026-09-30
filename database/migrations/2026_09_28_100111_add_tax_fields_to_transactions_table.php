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
        Schema::table('transactions', function (Blueprint $table) {
            // Tax category (see App\Support\Finance\TaxCategories) and, if only part counts
            // (e.g. labour costs for § 35a), the tax-relevant amount.
            $table->string('tax_category', 30)->nullable()->after('category_locked');
            $table->decimal('tax_amount', 12, 2)->nullable()->after('tax_category');
        });

        Schema::table('users', function (Blueprint $table) {
            // Personal marginal income tax rate in % for the tax estimates in Finances.
            $table->decimal('tax_rate', 5, 2)->default(30)->after('weather_longitude');
            $table->unsignedTinyInteger('tax_children')->default(0)->after('tax_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['tax_category', 'tax_amount']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'tax_children']);
        });
    }
};
