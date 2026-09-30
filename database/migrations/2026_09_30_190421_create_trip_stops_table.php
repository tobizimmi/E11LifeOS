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
        // Entries of the day-by-day trip plan (sights, food, activities …); without a day they are ideas.
        Schema::create('trip_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->date('day')->nullable();
            $table->string('time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->string('kind', 16)->default('sight');
            $table->string('title', 150);
            $table->string('location', 255)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->boolean('done')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['trip_id', 'day', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_stops');
    }
};
