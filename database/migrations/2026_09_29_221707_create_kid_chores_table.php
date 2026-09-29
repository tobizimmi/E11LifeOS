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
        Schema::create('kid_chores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            // Null: every child can do it.
            $table->foreignId('kid_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('emoji', 16)->default('⭐');
            $table->decimal('reward', 8, 2)->default(0);
            // daily, weekly (once per calendar week) or once
            $table->string('frequency', 8)->default('weekly');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kid_chores');
    }
};
