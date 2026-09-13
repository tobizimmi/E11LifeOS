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
        Schema::create('household_invites', function (Blueprint $table) {
            $table->id();
            // Null household_id = "new household" invite: the registrant creates
            // and owns a brand-new household. Set = "join this household" invite.
            $table->foreignId('household_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            // Null created_by = system-generated (e.g. seeded genesis invite, no user existed yet).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('household_invites');
    }
};
