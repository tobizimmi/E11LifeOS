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
        Schema::create('presence_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->nullable()->constrained()->nullOnDelete();
            $table->json('entities');
            $table->string('from', 5)->default('18:00');
            $table->string('until', 5)->default('23:30');
            $table->boolean('active')->default(true);
            $table->json('lit')->nullable(); // lights currently switched on by the simulation
            $table->timestamp('next_change_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('presence_simulations');
    }
};
