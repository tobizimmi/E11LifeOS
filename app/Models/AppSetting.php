<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * One server setting from the admin page (see App\Support\AppSettings), encrypted with APP_KEY.
 */
#[Fillable(['key', 'value'])]
#[Hidden(['value'])]
class AppSetting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }
}
