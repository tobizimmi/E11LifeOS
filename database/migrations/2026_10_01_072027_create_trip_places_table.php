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
        // Sights, restaurants … in the area of a trip, loaded once from OpenStreetMap for a fast search.
        Schema::create('trip_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('osm_id', 30);
            $table->string('name', 150);
            $table->string('kind', 20);
            $table->string('category', 40)->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->json('info')->nullable();
            $table->timestamps();

            $table->unique(['trip_id', 'osm_id']);
            $table->index(['trip_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_places');
    }
};
