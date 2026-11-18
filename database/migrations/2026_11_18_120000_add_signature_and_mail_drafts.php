<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            // Personal signature appended to new mails; encrypted at rest like the other content.
            $table->text('signature')->nullable()->after('last_error');
        });

        Schema::create('mail_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('to_addresses')->nullable();
            $table->text('cc')->nullable();
            $table->text('bcc')->nullable();
            $table->text('subject')->nullable();
            $table->longText('body')->nullable();
            $table->unsignedBigInteger('reply_to_id')->nullable();
            $table->timestamps();

            $table->index(['mail_account_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_drafts');

        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn('signature');
        });
    }
};
