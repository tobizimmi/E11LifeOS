<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->date('last_cooked_at')->nullable()->after('cooked_count');
        });

        // Known cooking days so far: past meal plan entries and ratings with "cooked today".
        $planned = DB::table('meal_plans')->whereNotNull('recipe_id')->where('date', '<=', now()->toDateString())
            ->groupBy('recipe_id')->pluck(DB::raw('MAX(date)'), 'recipe_id');
        $rated = DB::table('recipe_ratings')->whereNotNull('cooked_at')->groupBy('recipe_id')->pluck(DB::raw('MAX(cooked_at)'), 'recipe_id');

        foreach ($planned->keys()->merge($rated->keys())->unique() as $recipeId) {
            $dates = array_filter([$planned[$recipeId] ?? null, $rated[$recipeId] ?? null]);
            DB::table('recipes')->where('id', $recipeId)->update(['last_cooked_at' => substr((string) max($dates), 0, 10)]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('last_cooked_at');
        });
    }
};
