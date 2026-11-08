<?php

namespace App\Console\Commands;

use App\Models\Recipe;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('recipes:prune-temporary')]
#[Description('Delete temporary AI meal-planner recipes whose week is over (linked plan entries keep their title)')]
class PruneTemporaryRecipes extends Command
{
    public function handle(): int
    {
        $deleted = 0;

        // Delete per model so image cleanup runs; meal_plans.recipe_id is nullOnDelete, so the plan
        // entries survive with their title.
        Recipe::query()->expired()->chunkById(200, function ($recipes) use (&$deleted) {
            foreach ($recipes as $recipe) {
                $recipe->delete();
                $deleted++;
            }
        });

        $this->info("{$deleted} temporary recipe(s) pruned.");

        return self::SUCCESS;
    }
}
