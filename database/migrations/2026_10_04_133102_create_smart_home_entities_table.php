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
        Schema::create('smart_home_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('smart_home_connection_id')->constrained()->cascadeOnDelete();
            $table->string('entity_id', 191);
            $table->string('name', 150);
            $table->string('area', 100)->nullable();
            $table->string('unit', 30)->nullable();
            $table->string('state', 255)->nullable();
            $table->json('attributes')->nullable();
            $table->timestamp('state_changed_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['smart_home_connection_id', 'entity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smart_home_entities');
    }
};
