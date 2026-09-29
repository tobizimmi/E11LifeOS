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
        Schema::create('cashflow_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('category', 32);
            $table->string('label');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('value', 14, 2)->default(0);
            $table->boolean('productive')->default(true);
            $table->string('notes', 1000)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'kind']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('cashflow_manual_only')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cashflow_manual_only');
        });

        Schema::dropIfExists('cashflow_entries');
    }
};
