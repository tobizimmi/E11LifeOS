<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Savings goals of a member; the progress comes from a bank account or is entered by hand.
     */
    public function up(): void
    {
        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon', 16)->nullable();
            $table->decimal('target_amount', 14, 2);
            $table->decimal('saved_amount', 14, 2)->default(0);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('target_date')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_goals');
    }
};
