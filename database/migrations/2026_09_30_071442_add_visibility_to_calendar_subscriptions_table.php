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
        Schema::table('calendar_subscriptions', function (Blueprint $table) {
            // Private: only the owner sees the calendar; shared: every member of the household.
            $table->foreignId('household_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->string('visibility', 16)->default('private')->after('url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calendar_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('household_id');
            $table->dropColumn('visibility');
        });
    }
};
