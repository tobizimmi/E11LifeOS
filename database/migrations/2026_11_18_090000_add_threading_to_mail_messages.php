<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->text('in_reply_to')->nullable()->after('message_id');
            $table->text('references_ids')->nullable()->after('in_reply_to');
            $table->string('thread_key', 191)->nullable()->after('references_ids');

            $table->index(['mail_account_id', 'folder', 'thread_key']);
        });

        Schema::table('mail_accounts', function (Blueprint $table) {
            // UIDVALIDITY per folder: {"INBOX": 123, "Sent": 456}.
            $table->json('folder_uidvalidity')->nullable()->after('uid_validity');
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropIndex(['mail_account_id', 'folder', 'thread_key']);
            $table->dropColumn(['in_reply_to', 'references_ids', 'thread_key']);
        });

        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn('folder_uidvalidity');
        });
    }
};
