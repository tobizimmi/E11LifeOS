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
        Schema::create('smart_home_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->double('pv_kwh')->nullable();
            $table->double('home_kwh')->nullable();
            $table->double('import_kwh')->nullable();
            $table->double('export_kwh')->nullable();
            $table->double('charged_kwh')->nullable();
            $table->timestamps();
            $table->unique(['household_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smart_home_days');
    }
};
