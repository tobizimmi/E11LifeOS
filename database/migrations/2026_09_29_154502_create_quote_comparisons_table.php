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
        Schema::create('quote_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 16)->default('shared');
            $table->string('title');
            $table->string('icon', 16)->nullable();
            $table->text('notes')->nullable();
            $table->date('decide_by')->nullable();
            $table->foreignId('real_estate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('budget_item_id')->nullable()->constrained('real_estate_budget_items')->nullOnDelete();
            $table->unsignedBigInteger('chosen_quote_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_comparisons');
    }
};
