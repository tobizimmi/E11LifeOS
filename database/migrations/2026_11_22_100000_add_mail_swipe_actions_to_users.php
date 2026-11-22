<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user swipe actions for the mail list (Spark-style): the action for a right swipe and for a
     * left swipe. Defaults: right = flag, left = archive.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mail_swipe_right', 16)->default('flag');
            $table->string('mail_swipe_left', 16)->default('archive');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mail_swipe_right', 'mail_swipe_left']);
        });
    }
};
