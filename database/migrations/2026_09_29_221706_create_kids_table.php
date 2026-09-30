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
        Schema::create('kids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('emoji', 16)->default('🧒');
            $table->date('birth_date')->nullable();
            // Pocket money per week, booked on this weekday (1 = Monday … 7 = Sunday).
            $table->decimal('weekly_allowance', 8, 2)->nullable();
            $table->unsignedTinyInteger('allowance_day')->default(6);
            $table->date('last_allowance_on')->nullable();
            // Done chores are credited without a parent's confirmation.
            $table->boolean('auto_approve')->default(false);
            $table->string('goal_title', 100)->nullable();
            $table->decimal('goal_amount', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kids');
    }
};
