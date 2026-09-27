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
        Schema::table('portfolio_settings', function (Blueprint $table) {
            // Pushover on/off without deleting the keys, and how old pushed headlines may be.
            $table->boolean('notifications_enabled')->default(true)->after('pushover_app_token');
            $table->unsignedSmallInteger('news_push_max_age_hours')->default(24)->after('news_push_portfolio_only');
        });

        Schema::table('securities', function (Blueprint $table) {
            // Securities whose prices and news are fetched although nobody holds or watches them.
            $table->boolean('tracked')->default(false)->after('screeners');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portfolio_settings', function (Blueprint $table) {
            $table->dropColumn(['notifications_enabled', 'news_push_max_age_hours']);
        });

        Schema::table('securities', function (Blueprint $table) {
            $table->dropColumn('tracked');
        });
    }
};
