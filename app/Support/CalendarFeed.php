<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\Item;
use App\Models\User;
use App\Support\Life\LifeDates;
use App\Support\Portfolio\PortfolioLink;
use App\Support\RealEstate\RealEstateLink;
use Illuminate\Support\Carbon;

/**
 * iCalendar (ICS) feed of a member's open tasks with a due date and of collection dates
 * that have a reminder (e.g. cancellation deadlines), plus savings plans and expected dividends
 * of the member's portfolio, dates of the properties (invoices, subsidy deadlines, trades) and of
 * the life areas (trips, birthdays, vehicles, home; health only if chosen), for subscribing in
 * any calendar app. The member chooses the areas and whether details are included.
 */
class CalendarFeed
{
    private const PAST_DAYS = 60;

    private const FUTURE_DAYS = 400;

    private const TIMED_TASK_MINUTES = 30;

    /** Areas the member can switch on or off; health dates are off unless chosen. */
    public const SECTIONS = ['tasks', 'dates', 'life', 'health', 'real_estate', 'portfolio'];

    public const DEFAULT_SECTIONS = ['tasks', 'dates', 'life', 'real_estate', 'portfolio'];

    /**
     * @return array{sections: list<string>, details: bool}
     */
    public static function options(User $user): array
    {
        $options = $user->calendar_feed_options ?? [];

        return [
            'sections' => array_values(array_intersect(self::SECTIONS, $options['sections'] ?? self::DEFAULT_SECTIONS)),
            'details' => (bool) ($options['details'] ?? true),
        ];
    }

    public static function forUser(User $user): string
    {
        $household = $user->currentHousehold();
        $options = self::options($user);
        $has = fn (string $section) => in_array($section, $options['sections'], true);
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//E11LifeOS//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text(config('app.name')),
            'X-PUBLISHED-TTL:PT1H',
        ];

        if ($household) {
            $from = now()->subDays(self::PAST_DAYS)->toDateString();
            $until = now()->addDays(self::FUTURE_DAYS)->toDateString();

            Collection::query()->visibleTo($user, $household->id)->with('properties')->get()
                ->filter(fn (Collection $collection) => $has($collection->isTasks() ? 'tasks' : 'dates'))
                ->each(function (Collection $collection) use ($user, $from, $until, $options, &$lines) {
                    $dateProperties = $collection->isTasks()
                        ? collect([$collection->systemProperty('due')])->filter()
                        : $collection->properties->filter(fn ($property) => $property->remindDays() !== null);

                    if ($dateProperties->isEmpty()) {
                        return;
                    }

                    Item::query()->forHousehold($collection->household_id)->visibleTo($user)
                        ->where('collection_id', $collection->id)->where('done', false)->get()
                        ->each(function (Item $item) use ($collection, $dateProperties, $from, $until, $options, &$lines) {
                            foreach ($dateProperties as $property) {
                                $date = (string) $item->propertyValue($property);

                                if ($date !== '' && $date >= $from && $date <= $until) {
                                    array_push($lines, ...self::event($item, $collection, $property->name, $date, $options['details']));
                                }
                            }
                        });
                });

            if ($has('life') || $has('health')) {
                foreach (LifeDates::between($user, Carbon::parse($from), Carbon::parse($until)) as $event) {
                    if ($has($event['kind'] === 'health' ? 'health' : 'life')) {
                        array_push($lines, ...self::lifeEvent($event, $options['details']));
                    }
                }
            }

            if ($has('real_estate')) {
                foreach (RealEstateLink::events($user, $household->id, Carbon::parse($from), Carbon::parse($until)) as $event) {
                    array_push($lines, ...self::realEstateEvent($event));
                }
            }

            if ($has('portfolio') && $user->canUseAddon(Addons::PORTFOLIO)) {
                foreach (PortfolioLink::events($user, Carbon::parse($from), Carbon::parse($until)) as $event) {
                    array_push($lines, ...self::portfolioEvent($event, $options['details']));
                }
            }
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * @return array<int, string>
     */
    private static function event(Item $item, Collection $collection, string $propertyName, string $date, bool $details = true): array
    {
        $isTask = $collection->isTasks();
        $start = $isTask && $item->due_time ? Carbon::parse($date.' '.$item->due_time) : null;
        $lines = [
            'BEGIN:VEVENT',
            'UID:e11-'.$item->id.'-'.md5($propertyName).'@'.parse_url((string) config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.$item->updated_at->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.self::text($isTask ? $item->title : trim(($collection->icon ?: '').' '.$item->title.' – '.$propertyName)),
            'URL:'.($isTask ? route('tasks.index', ['task' => $item->id]) : route('collections.show', ['collection' => $collection->id, 'entry' => $item->id])),
        ];

        if ($start) {
            $lines[] = 'DTSTART:'.$start->copy()->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.$start->copy()->addMinutes(self::TIMED_TASK_MINUTES)->utc()->format('Ymd\THis\Z');
        } else {
            $day = Carbon::createFromFormat('!Y-m-d', $date);
            $lines[] = 'DTSTART;VALUE=DATE:'.$day->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$day->copy()->addDay()->format('Ymd');
        }

        $repeat = $isTask && ($property = $collection->systemProperty('repeat')) ? $item->propertyValue($property) : null;
        $frequency = ['daily' => 'DAILY', 'weekly' => 'WEEKLY', 'monthly' => 'MONTHLY', 'yearly' => 'YEARLY'][$repeat] ?? null;

        if ($frequency) {
            $lines[] = 'RRULE:FREQ='.$frequency;
        }

        if ($details && trim($item->body()) !== '') {
            $lines[] = 'DESCRIPTION:'.self::text(mb_substr(trim($item->body()), 0, 1000));
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * @param  array{date: string, kind: string, title: string, amount: ?float, symbol: string, uid: string}  $event
     * @return array<int, string>
     */
    private static function portfolioEvent(array $event, bool $details = true): array
    {
        $day = Carbon::createFromFormat('!Y-m-d', $event['date']);
        $amount = $details && $event['amount'] !== null ? ' ('.number_format($event['amount'], 2, ',', '.').' €)' : '';

        return [
            'BEGIN:VEVENT',
            'UID:e11-'.$event['uid'].'@'.parse_url((string) config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.self::text(($event['kind'] === 'dividend' ? '💰 ' : '📈 ').$event['title'].$amount),
            'URL:'.route('portfolio.security', ['symbol' => $event['symbol']]),
            'DTSTART;VALUE=DATE:'.$day->format('Ymd'),
            'DTEND;VALUE=DATE:'.$day->copy()->addDay()->format('Ymd'),
            'END:VEVENT',
        ];
    }

    /**
     * Trips, birthdays, vehicle and home dates, expiring documents and (if chosen) health dates.
     *
     * @param  array{date: string, title: string, url: string, kind: string}  $event
     * @return array<int, string>
     */
    private static function lifeEvent(array $event, bool $details): array
    {
        $day = Carbon::createFromFormat('!Y-m-d', $event['date']);
        $lines = [
            'BEGIN:VEVENT',
            'UID:e11-life-'.md5($event['kind'].'|'.$event['date'].'|'.$event['title']).'@'.parse_url((string) config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.self::text($event['title']),
            'DTSTART;VALUE=DATE:'.$day->format('Ymd'),
            'DTEND;VALUE=DATE:'.$day->copy()->addDay()->format('Ymd'),
        ];

        if ($details) {
            $lines[] = 'URL:'.$event['url'];
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Invoice due dates, subsidy deadlines and trade periods of the properties.
     *
     * @param  array{date: string, kind: string, title: string, url: string, uid: string, end?: ?string}  $event
     * @return array<int, string>
     */
    private static function realEstateEvent(array $event): array
    {
        $day = Carbon::createFromFormat('!Y-m-d', $event['date']);
        $end = ! empty($event['end']) && $event['end'] >= $event['date'] ? Carbon::createFromFormat('!Y-m-d', $event['end']) : $day;

        return [
            'BEGIN:VEVENT',
            'UID:e11-'.$event['uid'].'@'.parse_url((string) config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.self::text($event['title']),
            'URL:'.$event['url'],
            'DTSTART;VALUE=DATE:'.$day->format('Ymd'),
            'DTEND;VALUE=DATE:'.$end->copy()->addDay()->format('Ymd'),
            'END:VEVENT',
        ];
    }

    /**
     * Escapes a TEXT value (RFC 5545 §3.3.11).
     */
    private static function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', ''], $value);
    }

    /**
     * Lines longer than 75 octets continue on the next line after a space (RFC 5545 §3.1).
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $parts = [];

        while (strlen($line) > 75) {
            $cut = 75;

            // Never split a multi-byte UTF-8 character.
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }

            $parts[] = substr($line, 0, $cut);
            $line = ' '.substr($line, $cut);
        }

        $parts[] = $line;

        return implode("\r\n", $parts);
    }
}
