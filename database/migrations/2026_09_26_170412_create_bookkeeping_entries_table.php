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
        Schema::create('bookkeeping_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            // "expense" or "income" (income without an invoice from this app).
            $table->string('kind', 8)->default('expense');
            $table->date('booked_on');
            $table->string('description');
            $table->string('counterparty')->nullable();
            $table->string('category', 32);
            // Gross amount paid or received; vat_amount is the included VAT (Vorsteuer / USt).
            $table->decimal('amount', 12, 2);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name')->nullable();
            $table->string('receipt_mime', 128)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'booked_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookkeeping_entries');
    }
};
