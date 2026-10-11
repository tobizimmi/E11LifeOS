<?php

namespace App\Models;

use App\Models\Concerns\HasShareTokens;
use App\Support\SmartHome\EvccClient;
use App\Support\SmartHome\HomeAssistantClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $last_contact_at
 *
 * A Home Assistant or evcc installation of a household: reached in the home network through the
 * VPN, or through an external address (Nabu Casa) when the VPN is down.
 */
#[Fillable(['household_id', 'type', 'name', 'url', 'external_url', 'token', 'webhook_secret', 'last_contact_at', 'last_route', 'last_error', 'vehicle_map'])]
#[Hidden(['token', 'webhook_secret', 'webhook_secret_encrypted'])]
class SmartHomeConnection extends Model
{
    use HasShareTokens;

    public const HOME_ASSISTANT = 'homeassistant';

    public const EVCC = 'evcc';

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_contact_at' => 'datetime',
            'vehicle_map' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SmartHomeConnection $connection) {
            $connection->webhook_secret ??= Str::random(48);
        });
    }

    /**
     * @return list<string>
     */
    protected function shareTokenAttributes(): array
    {
        return ['webhook_secret'];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function types(): array
    {
        return [
            self::HOME_ASSISTANT => ['label' => 'Home Assistant', 'icon' => '🏠'],
            self::EVCC => ['label' => 'evcc', 'icon' => '🔌'],
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return HasMany<SmartHomeEntity, $this>
     */
    public function entities(): HasMany
    {
        return $this->hasMany(SmartHomeEntity::class);
    }

    public function isHomeAssistant(): bool
    {
        return $this->type === self::HOME_ASSISTANT;
    }

    public function homeAssistant(): HomeAssistantClient
    {
        return new HomeAssistantClient($this);
    }

    public function evcc(): EvccClient
    {
        return new EvccClient($this);
    }
}
