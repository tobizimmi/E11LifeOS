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
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('active')->default(true);
            $table->json('trigger');
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->unsignedSmallInteger('cooldown_minutes')->default(1);
            $table->boolean('trigger_state')->default(false); // whether the trigger condition held at the last check
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automations');
    }
};
