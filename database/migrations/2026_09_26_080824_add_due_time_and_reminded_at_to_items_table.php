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
        Schema::table('items', function (Blueprint $table) {
            // Optional time of a task's due date ("HH:MM"); a reminder goes out then.
            $table->string('due_time', 5)->nullable()->after('done');
            $table->timestamp('reminded_at')->nullable()->after('due_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['due_time', 'reminded_at']);
        });
    }
};
