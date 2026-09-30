<?php

namespace App\Support;

use App\Models\CalendarSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads external calendars (ICS links from Google, iCloud, Outlook …): events with their date or
 * time, including simple repetitions (daily, weekly, monthly, yearly with COUNT/UNTIL/INTERVAL).
 * Parsed events are cached for an hour.
 */
class IcsCalendar
{
    private const CACHE_MINUTES = 60;

    private const MAX_BYTES = 5_000_000;

    /**
     * Checks a calendar link: http(s) or webcal, no credentials, never the server itself.
     */
    public static function validUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https', 'webcal'], true) || empty($parts['host']) || isset($parts['user'])) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($ips as $ip) {
            if (str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.') || $ip === '0.0.0.0' || $ip === '::1') {
                return false;
            }
        }

        return ! in_array($host, ['localhost', 'metadata.google.internal'], true);
    }

    /**
     * Events of a subscription between two days (inclusive).
     *
     * @return list<array{date: string, time: ?string, end: ?string, title: string}>
     */
    public static function eventsBetween(CalendarSubscription $subscription, Carbon $from, Carbon $until): array
    {
        $events = Cache::remember('ics-calendar:'.$subscription->id.':'.md5((string) $subscription->updated_at), now()->addMinutes(self::CACHE_MINUTES), fn () => self::fetch($subscription));
        $fromDate = $from->toDateString();
        $untilDate = $until->toDateString();
        $result = [];

        foreach ($events as $event) {
            foreach (self::occurrences($event, $from, $until) as $start) {
                $date = $start->toDateString();

                if ($date >= $fromDate && $date <= $untilDate) {
                    $result[] = ['date' => $date, 'time' => $event['all_day'] ? null : $start->format('H:i'), 'end' => null, 'title' => $event['title']];
                }
            }
        }

        return $result;
    }

    /**
     * Downloads and parses the calendar; remembers errors on the subscription.
     *
     * @return list<array{start: string, all_day: bool, title: string, rrule: ?array<string, string>, exdates: list<string>}>
     */
    public static function fetch(CalendarSubscription $subscription): array
    {
        $url = preg_replace('#^webcal://#i', 'https://', trim($subscription->url));

        try {
            $response = Http::timeout(15)->withOptions(Vpn::httpOptions($url))->get($url)->throw();
            $body = substr($response->body(), 0, self::MAX_BYTES);
            $events = self::parse($body);
            $subscription->forceFill(['fetched_at' => now(), 'error' => null])->saveQuietly();

            return $events;
        } catch (Throwable $exception) {
            $subscription->forceFill(['error' => mb_substr($exception->getMessage(), 0, 250)])->saveQuietly();

            return [];
        }
    }

    /**
     * @return list<array{start: string, all_day: bool, title: string, rrule: ?array<string, string>, exdates: list<string>}>
     */
    public static function parse(string $ics): array
    {
        // Unfold continuation lines (RFC 5545 §3.1).
        $lines = preg_split('/\r?\n/', preg_replace('/\r?\n[ \t]/', '', $ics));
        $events = [];
        $current = null;

        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $current = ['start' => null, 'all_day' => false, 'title' => '', 'rrule' => null, 'exdates' => [], 'cancelled' => false];

                continue;
            }

            if ($line === 'END:VEVENT') {
                if ($current && $current['start'] && ! $current['cancelled']) {
                    unset($current['cancelled']);
                    $events[] = $current;
                }

                $current = null;

                continue;
            }

            if ($current === null || ! str_contains($line, ':')) {
                continue;
            }

            [$nameAndParams, $value] = explode(':', $line, 2);
            $params = explode(';', $nameAndParams);
            $name = strtoupper(array_shift($params));
            $paramMap = [];

            foreach ($params as $param) {
                [$key, $paramValue] = array_pad(explode('=', $param, 2), 2, '');
                $paramMap[strtoupper($key)] = trim($paramValue, '"');
            }

            match ($name) {
                'DTSTART' => [$current['start'], $current['all_day']] = self::parseDate($value, $paramMap),
                'SUMMARY' => $current['title'] = mb_substr(str_replace(['\\,', '\\;', '\\n', '\\N', '\\\\'], [',', ';', ' ', ' ', '\\'], $value), 0, 255),
                'RRULE' => $current['rrule'] = collect(explode(';', $value))->mapWithKeys(fn (string $part) => [strtoupper(strtok($part, '=')) => (string) substr($part, strpos($part, '=') + 1)])->all(),
                'EXDATE' => $current['exdates'] = [...$current['exdates'], ...array_map(fn (string $date) => substr(self::parseDate($date, $paramMap)[0] ?? '', 0, 10), explode(',', $value))],
                'STATUS' => $current['cancelled'] = strtoupper($value) === 'CANCELLED',
                default => null,
            };
        }

        return $events;
    }

    /**
     * @param  array<string, string>  $params
     * @return array{0: ?string, 1: bool} local start time (ISO) and whether it is a whole day
     */
    private static function parseDate(string $value, array $params): array
    {
        $value = trim($value);

        try {
            if (($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $value)) {
                return [Carbon::createFromFormat('!Ymd', substr($value, 0, 8))->toDateTimeString(), true];
            }

            $timezone = str_ends_with($value, 'Z') ? 'UTC' : ($params['TZID'] ?? config('app.timezone'));
            $date = Carbon::createFromFormat('Ymd\THis', rtrim($value, 'Z'), self::timezone($timezone));

            return [$date->setTimezone(config('app.timezone'))->toDateTimeString(), false];
        } catch (Throwable) {
            return [null, false];
        }
    }

    private static function timezone(string $name): string
    {
        return in_array($name, timezone_identifiers_list(), true) ? $name : (string) config('app.timezone');
    }

    /**
     * Start times of an event (and its repetitions) that may fall into the range.
     *
     * @param  array{start: string, all_day: bool, rrule: ?array<string, string>, exdates: list<string>}  $event
     * @return list<Carbon>
     */
    private static function occurrences(array $event, Carbon $from, Carbon $until): array
    {
        $start = Carbon::parse($event['start']);
        $rule = $event['rrule'];

        if (! $rule) {
            return [$start];
        }

        $step = match ($rule['FREQ'] ?? '') {
            'DAILY' => 'addDays',
            'WEEKLY' => 'addWeeks',
            'MONTHLY' => 'addMonthsNoOverflow',
            'YEARLY' => 'addYearsNoOverflow',
            default => null,
        };

        if (! $step) {
            return [$start];
        }

        $interval = max(1, (int) ($rule['INTERVAL'] ?? 1));
        $count = isset($rule['COUNT']) ? (int) $rule['COUNT'] : null;
        $ruleUntil = isset($rule['UNTIL']) ? Carbon::parse(substr($rule['UNTIL'], 0, 8))->endOfDay() : null;
        $end = $ruleUntil ? $ruleUntil->min($until->copy()->endOfDay()) : $until->copy()->endOfDay();
        $result = [];
        $current = $start->copy();

        for ($index = 0; $index < 2000 && $current->lte($end) && ($count === null || $index < $count); $index++) {
            if ($current->gte($from->copy()->startOfDay()) && ! in_array($current->toDateString(), $event['exdates'], true)) {
                $result[] = $current->copy();
            }

            $current = $start->copy()->{$step}($interval * ($index + 1));
        }

        return $result;
    }
}
