<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A brand or product whose offers the household follows ("Nutella", "Barilla", "Kaffee").
 */
#[Fillable(['household_id', 'term', 'created_by'])]
class ShoppingWatch extends Model {}
