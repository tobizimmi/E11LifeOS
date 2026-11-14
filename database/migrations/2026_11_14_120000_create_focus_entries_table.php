<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The "Focus" productivity module: a personal day plan built from a brain dump, sorted with the
     * Eisenhower matrix (quadrant), the day's most important tasks (MITs) and time boxing
     * (start_minute + duration). Each entry may link to a real to-do (item_id).
     */
    public function up(): void
    {
        Schema::create('focus_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->date('date');
            $table->string('title', 255);
            $table->string('quadrant', 4)->nullable(); // q1..q4 (Eisenhower); null = brain dump, not sorted yet
            $table->boolean('is_mit')->default(false);  // one of the day's most important tasks
            $table->unsignedSmallInteger('start_minute')->nullable(); // time box start, minutes from midnight
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->boolean('done')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_entries');
    }
};
