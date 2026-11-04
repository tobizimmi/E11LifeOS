<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A recurring payment already seen for a user, so the subscription radar can tell a newly appeared
 * subscription or a price increase from the ones already known.
 */
#[Fillable(['user_id', 'household_id', 'payment_key', 'name', 'amount'])]
class SubscriptionWatch extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'float',
        ];
    }
}
