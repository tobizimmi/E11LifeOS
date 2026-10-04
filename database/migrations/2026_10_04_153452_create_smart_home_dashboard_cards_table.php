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
        Schema::create('smart_home_dashboard_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smart_home_dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('smart_home_entity_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('entity');
            $table->string('display', 20)->default('tile');
            $table->string('size', 20)->default('small');
            $table->string('title')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smart_home_dashboard_cards');
    }
};
