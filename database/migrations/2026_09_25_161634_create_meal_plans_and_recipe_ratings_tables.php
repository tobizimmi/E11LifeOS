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
        Schema::table('recipes', function (Blueprint $table) {
            // ["recipes/1/uuid.jpg", ...] on the local disk, served through an authorized route.
            $table->json('additional_images')->nullable()->after('image_path');
        });

        // One household meal plan; entries are a recipe or free text ("Leftovers", "Eating out").
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Planning a private recipe makes the entry private as well (same rule as items).
            $table->string('visibility')->default('shared');
            $table->date('date');
            $table->string('meal_type', 20);
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            // Recipe title at planning time, or the free text; kept when the recipe is deleted.
            $table->string('title', 500);
            $table->unsignedInteger('servings')->nullable();
            $table->text('note')->nullable();
            $table->string('legacy_id', 191)->nullable();
            $table->timestamps();

            $table->index(['household_id', 'date']);
            $table->unique(['household_id', 'legacy_id']);
        });

        Schema::create('recipe_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->date('cooked_at')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_ratings');
        Schema::dropIfExists('meal_plans');

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('additional_images');
        });
    }
};
