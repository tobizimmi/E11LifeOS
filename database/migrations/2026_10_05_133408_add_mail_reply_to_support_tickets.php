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
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('reply_token', 40)->nullable()->unique()->after('satisfaction_comment');
        });

        Schema::table('support_messages', function (Blueprint $table) {
            $table->string('via', 10)->nullable()->after('kind');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropUnique(['reply_token']);
            $table->dropColumn('reply_token');
        });

        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn('via');
        });
    }
};
