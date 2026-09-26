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
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // "shared" = whole household, "private" = only created_by (same rule as items).
            $table->string('visibility')->default('shared');
            $table->string('title', 500);
            $table->text('description')->nullable();
            // [{"amount": "200", "unit": "g", "name": "Mehl"}, {"name": "Teig", "isSection": true}, ...]
            $table->json('ingredients')->nullable();
            // [{"text": "..."}, ...]
            $table->json('instructions')->nullable();
            $table->unsignedInteger('prep_time')->nullable();
            $table->unsignedInteger('cook_time')->nullable();
            $table->unsignedInteger('servings')->nullable();
            $table->string('difficulty', 50)->nullable();
            // Path on the local disk; served through an authorized route, never publicly.
            $table->string('image_path')->nullable();
            $table->string('source_url', 2000)->nullable();
            $table->string('source_type', 50)->nullable();
            $table->json('nutrition')->nullable();
            $table->unsignedInteger('cooked_count')->default(0);
            $table->timestamp('archived_at')->nullable();
            // Id in RezepteApp2, so the import can run again without duplicates.
            $table->string('legacy_id', 191)->nullable();
            $table->timestamps();

            $table->index(['household_id', 'archived_at']);
            $table->unique(['household_id', 'legacy_id']);
        });

        Schema::create('recipe_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('icon', 16)->nullable();
            $table->string('color', 50)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('legacy_id', 191)->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'name']);
        });

        Schema::create('recipe_category_recipe', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_category_id')->constrained()->cascadeOnDelete();

            $table->primary(['recipe_id', 'recipe_category_id']);
        });

        // Recipes use the household's shared tags (same table as pages and entries).
        Schema::create('recipe_tag', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['recipe_id', 'tag_id']);
        });

        Schema::create('recipe_favorites', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['recipe_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_favorites');
        Schema::dropIfExists('recipe_tag');
        Schema::dropIfExists('recipe_category_recipe');
        Schema::dropIfExists('recipe_categories');
        Schema::dropIfExists('recipes');
    }
};
