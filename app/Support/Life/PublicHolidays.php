<?php

namespace App\Support\Life;

use Illuminate\Support\Carbon;

/**
 * German public holidays per federal state (statutory, state-wide ones only).
 */
class PublicHolidays
{
    /**
     * @return array<string, string>
     */
    public static function states(): array
    {
        return [
            'BW' => 'Baden-Württemberg', 'BY' => 'Bayern', 'BE' => 'Berlin', 'BB' => 'Brandenburg', 'HB' => 'Bremen', 'HH' => 'Hamburg',
            'HE' => 'Hessen', 'MV' => 'Mecklenburg-Vorpommern', 'NI' => 'Niedersachsen', 'NW' => 'Nordrhein-Westfalen', 'RP' => 'Rheinland-Pfalz',
            'SL' => 'Saarland', 'SN' => 'Sachsen', 'ST' => 'Sachsen-Anhalt', 'SH' => 'Schleswig-Holstein', 'TH' => 'Thüringen',
        ];
    }

    /**
     * @return array<string, string> Y-m-d => name, sorted by date
     */
    public static function for(int $year, string $state): array
    {
        $easter = self::easterSunday($year);
        $in = fn (array $states) => in_array($state, $states, true);
        $day = fn (int $month, int $dayOfMonth) => Carbon::create($year, $month, $dayOfMonth)->toDateString();
        $fromEaster = fn (int $days) => $easter->copy()->addDays($days)->toDateString();

        $holidays = [
            $day(1, 1) => 'Neujahr',
            $fromEaster(-2) => 'Karfreitag',
            $fromEaster(1) => 'Ostermontag',
            $day(5, 1) => 'Tag der Arbeit',
            $fromEaster(39) => 'Christi Himmelfahrt',
            $fromEaster(50) => 'Pfingstmontag',
            $day(10, 3) => 'Tag der Deutschen Einheit',
            $day(12, 25) => '1. Weihnachtstag',
            $day(12, 26) => '2. Weihnachtstag',
        ];

        $optional = [
            [$in(['BW', 'BY', 'ST']), $day(1, 6), 'Heilige Drei Könige'],
            [$in(['BE']) || ($in(['MV']) && $year >= 2023), $day(3, 8), 'Internationaler Frauentag'],
            [$in(['BB']), $easter->toDateString(), 'Ostersonntag'],
            [$in(['BB']), $fromEaster(49), 'Pfingstsonntag'],
            [$in(['BW', 'BY', 'HE', 'NW', 'RP', 'SL']), $fromEaster(60), 'Fronleichnam'],
            [$in(['SL']), $day(8, 15), 'Mariä Himmelfahrt'],
            [$in(['TH']) && $year >= 2019, $day(9, 20), 'Weltkindertag'],
            [$in(['BB', 'MV', 'SN', 'ST', 'TH']) || ($in(['HB', 'HH', 'NI', 'SH']) && $year >= 2018), $day(10, 31), 'Reformationstag'],
            [$in(['BW', 'BY', 'NW', 'RP', 'SL']), $day(11, 1), 'Allerheiligen'],
            [$in(['SN']), self::repentanceDay($year), 'Buß- und Bettag'],
        ];

        foreach ($optional as [$applies, $date, $name]) {
            if ($applies) {
                $holidays[$date] = $name;
            }
        }

        ksort($holidays);

        return $holidays;
    }

    /**
     * Gauss/Meeus algorithm (no calendar extension needed).
     */
    public static function easterSunday(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $dayOfMonth = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $dayOfMonth)->startOfDay();
    }

    /**
     * The Wednesday before 23 November.
     */
    private static function repentanceDay(int $year): string
    {
        $date = Carbon::create($year, 11, 22);

        while (! $date->isWednesday()) {
            $date->subDay();
        }

        return $date->toDateString();
    }
}
