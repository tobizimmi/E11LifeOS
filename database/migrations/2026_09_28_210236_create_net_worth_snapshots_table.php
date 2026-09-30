<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per member and day for the net worth history.
     */
    public function up(): void
    {
        Schema::create('net_worth_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('taken_on');
            $table->decimal('assets', 14, 2);
            $table->decimal('liabilities', 14, 2);
            $table->json('breakdown')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'taken_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('net_worth_snapshots');
    }
};
