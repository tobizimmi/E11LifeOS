<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An installation of the native app that gets pushes through Apple's push service (APNs).
 *
 * @property int $id
 * @property int $user_id
 * @property string $platform
 * @property string $token
 * @property string $environment
 * @property ?string $name
 * @property ?Carbon $last_success_at
 * @property ?string $last_error
 */
#[Fillable(['user_id', 'platform', 'token', 'environment', 'name', 'last_success_at', 'last_error'])]
class DevicePushToken extends Model
{
    protected function casts(): array
    {
        return ['last_success_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
