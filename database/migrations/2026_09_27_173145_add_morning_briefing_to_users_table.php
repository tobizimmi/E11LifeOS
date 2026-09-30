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
        Schema::table('users', function (Blueprint $table) {
            // Morning briefing: "off", "email", "push" or "both", daily at hour.
            $table->string('morning_briefing', 10)->default('off')->after('weekly_digest_sent_at');
            $table->unsignedTinyInteger('morning_briefing_hour')->default(7)->after('morning_briefing');
            $table->date('morning_briefing_sent_on')->nullable()->after('morning_briefing_hour');
            // Place for the weather forecast (resolved via geocoding).
            $table->string('weather_place')->nullable()->after('morning_briefing_sent_on');
            $table->decimal('weather_latitude', 8, 5)->nullable()->after('weather_place');
            $table->decimal('weather_longitude', 8, 5)->nullable()->after('weather_latitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['morning_briefing', 'morning_briefing_hour', 'morning_briefing_sent_on', 'weather_place', 'weather_latitude', 'weather_longitude']);
        });
    }
};
