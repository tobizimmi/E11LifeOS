<?php

namespace App\Models;

use Database\Factories\RecipeCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['household_id', 'name', 'icon', 'color', 'position', 'legacy_id'])]
class RecipeCategory extends Model
{
    /** @use HasFactory<RecipeCategoryFactory> */
    use HasFactory;

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_category_recipe');
    }
}
