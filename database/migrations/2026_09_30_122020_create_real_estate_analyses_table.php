<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saved profitability calculations for a property (before buying or for an owned one).
     */
    public function up(): void
    {
        Schema::create('real_estate_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('real_estate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 150);
            $table->text('inputs');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('real_estate_analyses');
    }
};
