<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A read-only public link for a wiki page (and its shared subtree). Stored as HMAC plus an
     * encrypted copy, like the other share tokens; the link can be given an expiry.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('visibility');
            $table->text('public_token_encrypted')->nullable()->after('public_token');
            $table->timestamp('public_expires_at')->nullable()->after('public_token_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'public_token_encrypted', 'public_expires_at']);
        });
    }
};
