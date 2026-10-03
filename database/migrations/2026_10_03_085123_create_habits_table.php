<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Habits of a member (only visible to them): ticked off or counted, daily, on some weekdays
     * or a number of times per week, with an optional push reminder.
     */
    public function up(): void
    {
        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon', 16)->default('✅');
            $table->string('type', 10)->default('check');
            $table->unsignedSmallInteger('target')->default(1);
            $table->string('unit', 30)->nullable();
            $table->string('schedule', 16)->default('daily');
            $table->json('days')->nullable();
            $table->unsignedTinyInteger('per_week')->nullable();
            $table->string('reminder_at', 5)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'archived_at']);
            $table->index('reminder_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habits');
    }
};
