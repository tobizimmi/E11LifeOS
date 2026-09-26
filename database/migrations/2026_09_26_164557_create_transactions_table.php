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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('booked_on');
            // Negative = expense, positive = income.
            $table->decimal('amount', 12, 2);
            $table->string('counterparty')->nullable();
            $table->text('purpose')->nullable();
            $table->string('category', 32);
            // Set when the category was chosen by hand (not overwritten by rules or the AI).
            $table->boolean('category_locked')->default(false);
            $table->char('import_hash', 40);
            $table->timestamps();
            $table->unique(['bank_account_id', 'import_hash']);
            $table->index(['user_id', 'booked_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
