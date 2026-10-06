<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('household_id')->nullable();
            $table->string('institution_id');
            $table->string('institution_name');
            $table->string('logo')->nullable();
            $table->string('reference')->unique();
            $table->string('requisition_id')->nullable();
            $table->string('status', 20)->default('created');
            $table->text('account_ids')->nullable(); // encrypted JSON of GoCardless account ids
            $table->timestamp('consent_expires_at')->nullable();
            $table->boolean('auto_sync')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status', 20)->nullable();
            $table->string('sync_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_links');
    }
};
