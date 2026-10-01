<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Own notes and hints on a share (in the portfolio or on the watchlist), only for the member.
     */
    public function up(): void
    {
        Schema::create('security_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_notes');
    }
};
