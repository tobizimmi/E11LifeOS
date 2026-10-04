<?php

namespace App\Support\Automation;

use App\Models\Automation;
use App\Support\SmartHome\EntityStates;
use App\Support\Weather;

/**
 * Placeholders in the texts of actions: {reason} (why it ran), {automation}, {time}, {date},
 * {weekday}, {weather} (today's forecast at the member's weather place) and {state:entity_id}
 * (the current value of a Home Assistant entity).
 */
class Placeholders
{
    /**
     * @return array<string, string> placeholder => explanation, for the editor
     */
    public static function help(): array
    {
        return [
            '{reason}' => __('why it ran'),
            '{automation}' => __('name of the automation'),
            '{time}' => __('time'),
            '{date}' => __('date'),
            '{weekday}' => __('weekday'),
            '{weather}' => __('weather today'),
            '{state:sensor.temperature}' => __('value of a device'),
        ];
    }

    public static function fill(string $text, Automation $automation, string $reason): string
    {
        if (! str_contains($text, '{')) {
            return $text;
        }

        $text = preg_replace_callback('/\{state:([a-z_]+\.[a-z0-9_]+)\}/', fn (array $match) => (string) (EntityStates::get($automation->household_id, $match[1]) ?? '–'), $text) ?? $text;

        return strtr($text, [
            '{reason}' => $reason,
            '{automation}' => $automation->name,
            '{time}' => now()->format('H:i'),
            '{date}' => now()->translatedFormat('j. F Y'),
            '{weekday}' => now()->translatedFormat('l'),
            '{weather}' => str_contains($text, '{weather}') ? self::weather($automation) : '',
        ]);
    }

    /**
     * The text fields of an action with the placeholders filled in.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public static function apply(array $action, Automation $automation, string $reason): array
    {
        foreach (AutomationCatalog::TEXT_FIELDS as $field) {
            if (isset($action[$field]) && is_string($action[$field])) {
                $action[$field] = self::fill($action[$field], $automation, $reason);
            }
        }

        return $action;
    }

    private static function weather(Automation $automation): string
    {
        $user = $automation->user;

        if (! $user || $user->weather_latitude === null || $user->weather_longitude === null) {
            return '';
        }

        $today = Weather::today((float) $user->weather_latitude, (float) $user->weather_longitude);

        return $today ? Weather::summary($today) : '';
    }
}
