<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('provider', 20)->default('generic_imap');
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 10)->default('ssl');
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 10)->nullable();
            $table->boolean('validate_cert')->default(true);
            $table->text('username');
            $table->text('password');
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'household_id']);
        });

        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('folder')->default('INBOX');
            $table->unsignedBigInteger('uid');
            $table->string('message_id', 998)->nullable();
            $table->text('from_address')->nullable();
            $table->text('from_name')->nullable();
            $table->text('to_addresses')->nullable();
            $table->text('subject')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->timestamp('date')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->boolean('is_read')->default(false);
            $table->boolean('is_flagged')->default(false);
            $table->boolean('has_attachments')->default(false);
            $table->text('attachments_meta')->nullable();
            $table->string('processed_as', 20)->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'folder', 'uid']);
            $table->index(['mail_account_id', 'folder', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_accounts');
    }
};
