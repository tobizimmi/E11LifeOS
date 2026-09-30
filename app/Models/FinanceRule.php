<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * "Always put transactions containing <pattern> into <category>" – created when a
 * category is changed by hand with "remember".
 */
#[Fillable(['user_id', 'pattern', 'category'])]
class FinanceRule extends Model {}
