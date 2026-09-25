<?php

namespace App\Support;

/**
 * Finds durations in a preparation step ("10 Minuten köcheln", "1,5 Std.", "ca. 20-25 min")
 * so cooking mode can offer matching timers. Ranges use the shorter time, so nothing burns.
 */
class StepTimers
{
    private const PATTERN = '/(\d+(?:[.,]\d+)?)(?:\s*(?:-|–|bis)\s*\d+(?:[.,]\d+)?)?\s*(stunden|stunde|std\.?|h|minuten|minute|min\.?|sekunden|sek\.?|s)(?![\p{L}])/iu';

    /**
     * @return array<int, array{label: string, seconds: int}>
     */
    public static function detect(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $match) {
                $value = (float) str_replace(',', '.', $match[1]);
                $unit = mb_strtolower(rtrim($match[2], '.'));

                $seconds = (int) round($value * match (true) {
                    in_array($unit, ['stunden', 'stunde', 'std', 'h'], true) => 3600,
                    in_array($unit, ['sekunden', 'sek', 's'], true) => 1,
                    default => 60,
                });

                return ['label' => trim($match[0]), 'seconds' => $seconds];
            })
            // Plausible kitchen timers only (1 second to 24 hours), each duration once.
            ->filter(fn (array $timer) => $timer['seconds'] > 0 && $timer['seconds'] <= 86400)
            ->unique('seconds')
            ->values()
            ->all();
    }
}
