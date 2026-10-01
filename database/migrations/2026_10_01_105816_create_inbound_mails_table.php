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
        Schema::create('inbound_mails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message_id', 191)->nullable();
            $table->text('from_address');
            $table->text('subject')->nullable();
            $table->string('status', 20);
            $table->json('drafts')->nullable();
            $table->unsignedSmallInteger('attachments')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'created_at']);
            $table->unique(['household_id', 'message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbound_mails');
    }
};
