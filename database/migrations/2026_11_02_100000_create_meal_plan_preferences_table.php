<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user preferences for the AI meal planner: budget, store, diet and the rest of the
     * constraints the planner reasons with. Array-valued settings are stored as JSON.
     */
    public function up(): void
    {
        Schema::create('meal_plan_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('people')->default(2);
            $table->unsignedInteger('days_per_week')->default(5);
            $table->decimal('weekly_budget', 8, 2)->nullable();
            $table->string('postal_code', 12)->nullable();
            $table->string('diet', 40)->nullable();          // e.g. omnivore, vegetarian, vegan, pescetarian
            $table->boolean('meal_prep')->default(false);
            // Array-valued settings.
            $table->json('meals')->nullable();               // ['dinner'] or ['breakfast','lunch','dinner']
            $table->json('supermarkets')->nullable();        // ['Rewe','Aldi']
            $table->json('allergens')->nullable();           // ['nuts','lactose']
            $table->json('dish_types')->nullable();          // ['healthy','high_protein','low_carb']
            $table->json('cuisines')->nullable();            // ['italian','asian']
            $table->json('disliked_ingredients')->nullable();// ['coriander','olives']
            $table->json('appliances')->nullable();          // ['oven','air_fryer','thermomix']
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_preferences');
    }
};
