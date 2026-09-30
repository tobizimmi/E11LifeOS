<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Places to remember: restaurants, cafés, bars, sights, shops – to try out or already visited.
     */
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visibility', 10)->default('shared');
            $table->string('name', 150);
            $table->string('category', 20)->default('restaurant');
            $table->string('cuisine', 60)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('phone', 40)->nullable();
            $table->unsignedTinyInteger('price_level')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('recommended_by', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('visited')->default(false);
            $table->date('last_visited_on')->nullable();
            $table->unsignedSmallInteger('visit_count')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
