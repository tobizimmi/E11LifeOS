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
        Schema::create('meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('kind', 16)->default('electricity');
            $table->string('number', 64)->nullable();
            $table->decimal('price_per_unit', 10, 4)->nullable();
            $table->decimal('base_fee_monthly', 10, 2)->nullable();
            $table->decimal('monthly_advance', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('read_on');
            $table->decimal('value', 14, 3);
            $table->timestamps();
            $table->index(['meter_id', 'read_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
        Schema::dropIfExists('meters');
    }
};
