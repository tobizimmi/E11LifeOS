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
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_comparison_id')->constrained()->cascadeOnDelete();
            $table->string('vendor');
            $table->decimal('amount', 12, 2)->nullable();
            $table->date('quoted_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('delivery')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('pros')->nullable();
            $table->text('cons')->nullable();
            $table->text('notes')->nullable();
            $table->string('url', 2000)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
