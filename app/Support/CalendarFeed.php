<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\Item;
use App\Models\User;
use App\Support\Portfolio\PortfolioLink;
use Illuminate\Support\Carbon;

/**
 * iCalendar (ICS) feed of a member's open tasks with a due date and of collection dates
 * that have a reminder (e.g. cancellation deadlines), plus savings plans and expected dividends
 * of the member's portfolio, for subscribing in any calendar app.
 */
class CalendarFeed
{
    private const PAST_DAYS = 60;

    private const FUTURE_DAYS = 400;

    private const TIMED_TASK_MINUTES = 30;

    public static function forUser(User $user): string
    {
        $household = $user->currentHousehold();
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
                ->each(function (Collection $collection) use ($user, $from, $until, &$lines) {
                    $dateProperties = $collection->isTasks()
                        ? collect([$collection->systemProperty('due')])->filter()
                        : $collection->properties->filter(fn ($property) => $property->remindDays() !== null);

                    if ($dateProperties->isEmpty()) {
                        return;
                    }

                    Item::query()->forHousehold($collection->household_id)->visibleTo($user)
                        ->where('collection_id', $collection->id)->where('done', false)->get()
                        ->each(function (Item $item) use ($collection, $dateProperties, $from, $until, &$lines) {
                            foreach ($dateProperties as $property) {
                                $date = (string) $item->propertyValue($property);

                                if ($date !== '' && $date >= $from && $date <= $until) {
                                    array_push($lines, ...self::event($item, $collection, $property->name, $date));
                                }
                            }
                        });
                });

            if ($user->canUseAddon(Addons::PORTFOLIO)) {
                foreach (PortfolioLink::events($user, Carbon::parse($from), Carbon::parse($until)) as $event) {
                    array_push($lines, ...self::portfolioEvent($event));
                }
            }
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * @return array<int, string>
     */
    private static function event(Item $item, Collection $collection, string $propertyName, string $date): array
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

        if (trim($item->body()) !== '') {
            $lines[] = 'DESCRIPTION:'.self::text(mb_substr(trim($item->body()), 0, 1000));
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * @param  array{date: string, kind: string, title: string, amount: ?float, symbol: string, uid: string}  $event
     * @return array<int, string>
     */
    private static function portfolioEvent(array $event): array
    {
        $day = Carbon::createFromFormat('!Y-m-d', $event['date']);
        $amount = $event['amount'] !== null ? ' ('.number_format($event['amount'], 2, ',', '.').' €)' : '';

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
