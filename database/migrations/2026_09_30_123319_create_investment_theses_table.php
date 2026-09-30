<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A member's own reasoning per security: why they own it, moat, risks, buy and sell rules.
     */
    public function up(): void
    {
        Schema::create('investment_theses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('stock_type', 20)->nullable();
            $table->text('thesis')->nullable();
            $table->text('moat')->nullable();
            $table->text('risks')->nullable();
            $table->text('sell_if')->nullable();
            $table->decimal('buy_below', 14, 4)->nullable();
            $table->date('review_on')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_theses');
    }
};
