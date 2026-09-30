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
            // Weekly summary: "off", "email", "push" or "both", on ISO weekday (1 = Monday) at hour.
            $table->string('weekly_digest', 10)->default('off')->after('reminder_emails');
            $table->unsignedTinyInteger('weekly_digest_day')->default(7)->after('weekly_digest');
            $table->unsignedTinyInteger('weekly_digest_hour')->default(18)->after('weekly_digest_day');
            $table->timestamp('weekly_digest_sent_at')->nullable()->after('weekly_digest_hour');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['weekly_digest', 'weekly_digest_day', 'weekly_digest_hour', 'weekly_digest_sent_at']);
        });
    }
};
