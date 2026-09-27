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
        // Monthly spending limit per category and user (finances are personal).
        Schema::create('finance_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('category', 50);
            $table->decimal('monthly_limit', 12, 2);
            // Month ("2026-09") and level (80 / 100) of the last warning, so each is sent once.
            $table->string('warned_month', 7)->nullable();
            $table->unsignedTinyInteger('warned_level')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'household_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_budgets');
    }
};
