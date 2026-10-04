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
        Schema::create('household_modes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50);
            $table->string('name', 100);
            $table->string('icon', 16)->default('🏷️');
            $table->boolean('active')->default(false);
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
            $table->unique(['household_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('household_modes');
    }
};
