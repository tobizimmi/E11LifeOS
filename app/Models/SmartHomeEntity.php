<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Home Assistant entity the household picked for the Smart Home page and automations, with its
 * last known state (from polling or the webhook).
 */
#[Fillable(['household_id', 'smart_home_connection_id', 'entity_id', 'name', 'area', 'unit', 'state', 'attributes', 'state_changed_at', 'position'])]
class SmartHomeEntity extends Model
{
    /** Domains that can be switched on and off with a tap. */
    public const SWITCHABLE = ['light', 'switch', 'input_boolean', 'fan', 'automation'];

    /** Domains that are started with a tap. */
    public const RUNNABLE = ['scene', 'script', 'button', 'input_button'];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'state_changed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SmartHomeConnection::class, 'smart_home_connection_id');
    }

    public function domain(): string
    {
        return (string) strtok($this->entity_id, '.');
    }

    public function isSwitchable(): bool
    {
        return in_array($this->domain(), self::SWITCHABLE, true);
    }

    public function isRunnable(): bool
    {
        return in_array($this->domain(), self::RUNNABLE, true);
    }

    public function isOn(): bool
    {
        return in_array($this->state, ['on', 'open', 'home', 'playing'], true);
    }

    public function icon(): string
    {
        return match ($this->domain()) {
            'light' => '💡',
            'switch', 'input_boolean' => '🔘',
            'scene' => '🎬',
            'script', 'button', 'input_button' => '▶️',
            'climate' => '🌡️',
            'cover' => '🪟',
            'lock' => '🔒',
            'binary_sensor' => '🟢',
            'person', 'device_tracker' => '🧑',
            'fan' => '🌀',
            'media_player' => '🔊',
            default => '📟',
        };
    }

    /**
     * State as shown on the tile ("21.5 °C", "An", "Offen").
     */
    public function displayState(): string
    {
        if ($this->state === null || $this->state === '') {
            return '–';
        }

        if (is_numeric($this->state)) {
            return number_format((float) $this->state, str_contains($this->state, '.') ? 1 : 0, ',', '.').($this->unit ? ' '.$this->unit : '');
        }

        return match ($this->state) {
            'on' => __('On'),
            'off' => __('Off'),
            'open' => __('Open'),
            'closed' => __('Closed'),
            'home' => __('At home'),
            'not_home' => __('Away'),
            'unavailable' => __('Unavailable'),
            'locked' => __('Locked'),
            'unlocked' => __('Unlocked'),
            default => $this->state.($this->unit ? ' '.$this->unit : ''),
        };
    }
}
