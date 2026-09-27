<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Today's weather from Open-Meteo (free, no API key) and place search for the user's location.
 */
class Weather
{
    /**
     * @return array{name: string, latitude: float, longitude: float}|null
     */
    public static function geocode(string $place): ?array
    {
        try {
            $result = Http::timeout(8)->get('https://geocoding-api.open-meteo.com/v1/search', [
                'name' => $place, 'count' => 1, 'language' => app()->getLocale(), 'format' => 'json',
            ])->json('results.0');
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_array($result) || ! isset($result['latitude'], $result['longitude'])) {
            return null;
        }

        return [
            'name' => trim(($result['name'] ?? $place).(isset($result['admin1']) && $result['admin1'] !== ($result['name'] ?? '') ? ', '.$result['admin1'] : '')),
            'latitude' => round((float) $result['latitude'], 5),
            'longitude' => round((float) $result['longitude'], 5),
        ];
    }

    /**
     * Forecast for today, cached for an hour; null if the service is not reachable.
     *
     * @return array{icon: string, label: string, min: int, max: int, rain: int}|null
     */
    public static function today(float $latitude, float $longitude): ?array
    {
        $key = 'weather:'.round($latitude, 2).':'.round($longitude, 2).':'.today()->toDateString();

        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        try {
            $daily = Http::timeout(8)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                'timezone' => config('app.timezone'),
                'forecast_days' => 1,
            ])->json('daily');
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_array($daily) || ! isset($daily['weather_code'][0], $daily['temperature_2m_max'][0], $daily['temperature_2m_min'][0])) {
            return null;
        }

        [$icon, $label] = self::describe((int) $daily['weather_code'][0]);
        $weather = [
            'icon' => $icon,
            'label' => $label,
            'min' => (int) round((float) $daily['temperature_2m_min'][0]),
            'max' => (int) round((float) $daily['temperature_2m_max'][0]),
            'rain' => (int) ($daily['precipitation_probability_max'][0] ?? 0),
        ];

        Cache::put($key, $weather, now()->addHour());

        return $weather;
    }

    /**
     * "☀️ Sunny, 12–21 °C, rain 10 %".
     *
     * @param  array{icon: string, label: string, min: int, max: int, rain: int}  $weather
     */
    public static function summary(array $weather): string
    {
        return $weather['icon'].' '.$weather['label'].', '.$weather['min'].'–'.$weather['max'].' °C'
            .($weather['rain'] >= 20 ? ', '.__('rain :percent %', ['percent' => $weather['rain']]) : '');
    }

    /**
     * WMO weather code → icon and label.
     *
     * @return array{0: string, 1: string}
     */
    private static function describe(int $code): array
    {
        return match (true) {
            $code === 0 => ['☀️', __('Sunny')],
            $code <= 2 => ['🌤️', __('Partly cloudy')],
            $code === 3 => ['☁️', __('Cloudy')],
            $code <= 48 => ['🌫️', __('Fog')],
            $code <= 57 => ['🌦️', __('Drizzle')],
            $code <= 67 || ($code >= 80 && $code <= 82) => ['🌧️', __('Rain')],
            $code <= 77 || ($code >= 85 && $code <= 86) => ['🌨️', __('Snow')],
            default => ['⛈️', __('Thunderstorms')],
        };
    }
}
