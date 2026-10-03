<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Own points (0–3) per factor of the points system for a share, only for the member.
     */
    public function up(): void
    {
        Schema::create('stock_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->json('points');
            $table->timestamps();

            $table->unique(['user_id', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ratings');
    }
};
