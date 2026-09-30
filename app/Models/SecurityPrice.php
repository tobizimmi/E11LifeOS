<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily prices of a security.
 */
#[Fillable(['symbol', 'date', 'open', 'high', 'low', 'close', 'volume'])]
class SecurityPrice extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'open' => 'float',
            'high' => 'float',
            'low' => 'float',
            'close' => 'float',
            'volume' => 'integer',
        ];
    }
}
