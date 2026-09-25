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
        Schema::create('collection_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // text, number, date, select, checkbox, person, url
            $table->string('type');
            // Select options: [{"id": "...", "name": "...", "color": "..."}]
            $table->json('options')->nullable();
            // Built-in properties of system collections (e.g. "status"); cannot be deleted.
            $table->string('system_key')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_properties');
    }
};
