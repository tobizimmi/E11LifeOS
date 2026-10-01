<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Headline, summary, skills and the wished-for job of the member (for the CV and job suggestions).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('career_profile')->nullable()->after('cashflow_categories');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('career_profile');
        });
    }
};
