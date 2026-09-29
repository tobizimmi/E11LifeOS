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
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 16)->default('shared');
            $table->string('title', 150);
            $table->string('destination', 150)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('budget', 12, 2)->nullable();
            // Who travels (free text, e.g. "Tobi, Anna, Mia").
            $table->string('travelers')->nullable();
            // Packing list templates applied (beach, winter …).
            $table->json('packing_templates')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'starts_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
