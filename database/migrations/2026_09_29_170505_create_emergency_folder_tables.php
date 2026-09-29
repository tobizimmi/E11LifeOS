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
        Schema::create('emergency_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('section', 32);
            $table->string('kind', 48);
            $table->string('title');
            $table->text('details')->nullable();
            $table->text('location')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedSmallInteger('remind_days')->default(60);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'section']);
        });

        Schema::create('trusted_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->unsignedSmallInteger('wait_days')->default(7);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trusted_people');
        Schema::dropIfExists('emergency_entries');
    }
};
