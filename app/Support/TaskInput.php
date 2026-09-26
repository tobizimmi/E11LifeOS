<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\Item;
use Illuminate\Support\Carbon;

/**
 * Reads a quick-entry line like "Zahnarzt anrufen morgen !hoch @Anna #gesundheit" (typed or
 * dictated): due date, repetition, priority, assignee and tags are taken out of the title.
 */
class TaskInput
{
    private const WEEKDAYS = ['montag' => 1, 'dienstag' => 2, 'mittwoch' => 3, 'donnerstag' => 4, 'freitag' => 5, 'samstag' => 6, 'sonntag' => 0];

    private const WEEKDAY_PATTERN = '(montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag)';

    /**
     * @param  array<int, string>  $members  Member names by user id (for "@name").
     * @param  array<int, string>  $ignored  Recognised phrases the user wants to keep in the title.
     * @return array{title: string, due: ?string, time: ?string, repeat: ?string, priority: ?string, assignee: ?int, tags: array<int, string>, tokens: array<int, array{kind: string, text: string, label: string}>}
     */
    public static function parse(string $text, array $members = [], ?int $currentUserId = null, array $ignored = [], ?Carbon $today = null): array
    {
        $now = ($today ?? now())->copy();
        $today = $now->copy()->startOfDay();
        $ignored = array_map(fn (string $phrase) => mb_strtolower(trim($phrase)), $ignored);
        $result = ['title' => '', 'due' => null, 'time' => null, 'repeat' => null, 'priority' => null, 'assignee' => null, 'tags' => [], 'tokens' => []];
        $rest = ' '.preg_replace('/\s+/u', ' ', trim($text)).' ';

        $take = function (string $pattern, callable $handle) use (&$rest, &$result, $ignored): bool {
            if (! preg_match('/(?<=\s)'.$pattern.'(?=[\s,.!?;:]|$)/iu', $rest, $match, PREG_OFFSET_CAPTURE)) {
                return false;
            }

            $phrase = trim($match[0][0]);

            if (in_array(mb_strtolower($phrase), $ignored, true)) {
                return false;
            }

            $token = $handle(array_map(fn (array $group) => mb_strtolower($group[0]), $match));

            if ($token === null) {
                return false;
            }

            $result['tokens'][] = $token + ['text' => $phrase];
            $rest = substr($rest, 0, $match[0][1]).' '.substr($rest, $match[0][1] + strlen($match[0][0]));

            return true;
        };

        $setDue = function (?Carbon $date) use (&$result): ?array {
            if ($date === null) {
                return null;
            }

            $result['due'] = $date->toDateString();

            return ['kind' => 'due', 'label' => self::dueLabel($result['due'])];
        };

        // Priority: "!!!" / "!1" / "!hoch" … "!3" / "!niedrig".
        foreach ([
            Collection::TASK_PRIORITY_HIGH => '(!!!|!1|!hoch|!high|!wichtig|!dringend)',
            Collection::TASK_PRIORITY_MEDIUM => '(!!|!2|!mittel|!medium)',
            Collection::TASK_PRIORITY_LOW => '(!3|!niedrig|!low)',
        ] as $priority => $pattern) {
            if ($take($pattern, function () use (&$result, $priority) {
                $result['priority'] = $priority;

                return ['kind' => 'priority', 'label' => self::priorityLabel($priority)];
            })) {
                break;
            }
        }

        // Repetition ("jeden Montag" also sets the first due date).
        $take('jede[nsm]? '.self::WEEKDAY_PATTERN, function (array $match) use (&$result, $today) {
            $first = self::nextWeekday($today, self::WEEKDAYS[$match[1]], true);
            $result['repeat'] = Recurrence::WEEKLY;
            $result['due'] = $first->toDateString();

            return ['kind' => 'repeat', 'label' => __('Every :weekday', ['weekday' => $first->translatedFormat('l')])];
        });

        if ($result['repeat'] === null) {
            foreach ([
                Recurrence::DAILY => '(täglich|jeden tag|daily)',
                Recurrence::WEEKLY => '(wöchentlich|jede woche|weekly)',
                Recurrence::MONTHLY => '(monatlich|jeden monat|monthly)',
                Recurrence::YEARLY => '(jährlich|jedes jahr|yearly)',
            ] as $rule => $pattern) {
                if ($take($pattern, function () use (&$result, $rule) {
                    $result['repeat'] = $rule;

                    return ['kind' => 'repeat', 'label' => self::repeatLabel($rule)];
                })) {
                    break;
                }
            }
        }

        // Time of day: "18 Uhr", "um 18:30", "18.30 Uhr", "7:05".
        foreach (['(?:um |ab |gegen )?(\d{1,2})(?:[:.](\d{2}))? ?uhr', '(?:um |ab |gegen )?(\d{1,2}):(\d{2})'] as $pattern) {
            if ($take($pattern, function (array $match) use (&$result) {
                $hour = (int) $match[1];
                $minute = (int) (($match[2] ?? '') !== '' ? $match[2] : 0);

                if ($hour > 23 || $minute > 59) {
                    return null;
                }

                $result['time'] = sprintf('%02d:%02d', $hour, $minute);

                return ['kind' => 'time', 'label' => $result['time']];
            })) {
                break;
            }
        }

        // Due date: the first recognised expression wins.
        $dateRules = [
            '(?:am |bis )?(\d{4})-(\d{2})-(\d{2})' => fn (array $m) => self::date((int) $m[1], (int) $m[2], (int) $m[3]),
            '(?:am |bis )?(\d{1,2})\.(\d{1,2})\.(\d{2,4})?' => function (array $m) use ($today) {
                $year = ($m[3] ?? '') !== '' ? (int) $m[3] : $today->year;
                $year = $year < 100 ? 2000 + $year : $year;
                $date = self::date($year, (int) $m[2], (int) $m[1]);

                return $date && ($m[3] ?? '') === '' && $date->lt($today) ? $date->addYear() : $date;
            },
            'in (\d{1,3}) (tag|tagen|woche|wochen|monat|monaten)' => fn (array $m) => match (true) {
                str_starts_with($m[2], 'tag') => $today->copy()->addDays((int) $m[1]),
                str_starts_with($m[2], 'woche') => $today->copy()->addWeeks((int) $m[1]),
                default => $today->copy()->addMonthsNoOverflow((int) $m[1]),
            },
            'übermorgen' => fn () => $today->copy()->addDays(2),
            'morgen' => fn () => $today->copy()->addDay(),
            'heute' => fn () => $today->copy(),
            '(?:nächste|kommende) woche' => fn () => self::nextWeekday($today, 1, false),
            '(?:am |dieses |nächstes )?wochenende' => fn () => self::nextWeekday($today, 6, true),
            '(?:am |nächsten |kommenden |diesen )?'.self::WEEKDAY_PATTERN => fn (array $m) => self::nextWeekday($today, self::WEEKDAYS[$m[1]], ! str_contains($m[0], 'nächsten') && ! str_contains($m[0], 'kommenden')),
        ];

        if ($result['due'] === null) {
            foreach ($dateRules as $pattern => $resolve) {
                if ($take($pattern, fn (array $match) => $setDue($resolve($match)))) {
                    break;
                }
            }
        }

        // Only a time: today, or tomorrow if that time has already passed.
        if ($result['time'] !== null && $result['due'] === null) {
            $result['due'] = ($now->format('H:i') < $result['time'] ? $today : $today->copy()->addDay())->toDateString();
        }

        // Assignee: "@Anna" (start of a member's name) or "@ich".
        $take('@([\p{L}\p{N}_-]+)', function (array $match) use (&$result, $members, $currentUserId) {
            $name = $match[1];
            $userId = in_array($name, ['ich', 'mich', 'me'], true) ? $currentUserId : null;
            $userId ??= collect($members)->search(fn (string $member) => str_starts_with(mb_strtolower($member), $name)) ?: null;

            if (! $userId) {
                return null;
            }

            $result['assignee'] = (int) $userId;

            return ['kind' => 'assignee', 'label' => '@'.($members[$userId] ?? $name)];
        });

        [$title, $tags] = Item::splitHashtags(trim(preg_replace(['/\s+/u', '/\s+([!?.,;:])(?=\s|$)/u'], [' ', '$1'], $rest)));
        $result['tags'] = $tags;

        foreach ($tags as $tag) {
            $result['tokens'][] = ['kind' => 'tag', 'text' => '#'.$tag, 'label' => '#'.$tag];
        }

        // "Arzt anrufen am" → "Arzt anrufen" after the date was taken out.
        $result['title'] = trim(preg_replace('/\s+(am|bis|um|ab)$/iu', '', $title), " \t,;:-");

        return $result;
    }

    /**
     * "Heute", "Morgen", "Freitag" (within a week) or "3. Okt." / "3. Okt. 2027".
     */
    public static function dueLabel(string $date, ?Carbon $today = null): string
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $due = Carbon::createFromFormat('!Y-m-d', $date);
        $days = (int) $today->diffInDays($due, false);

        return match (true) {
            $days === 0 => __('Today'),
            $days === 1 => __('Tomorrow'),
            $days === -1 => __('Yesterday'),
            $days > 1 && $days < 7 => $due->translatedFormat('l'),
            $due->year === $today->year => $due->translatedFormat('j. M'),
            default => $due->translatedFormat('j. M Y'),
        };
    }

    public static function repeatLabel(string $rule): string
    {
        return match ($rule) {
            Recurrence::DAILY => __('Daily'),
            Recurrence::WEEKLY => __('Weekly'),
            Recurrence::MONTHLY => __('Monthly'),
            default => __('Yearly'),
        };
    }

    public static function priorityLabel(string $priority): string
    {
        return match ($priority) {
            Collection::TASK_PRIORITY_HIGH => __('High'),
            Collection::TASK_PRIORITY_MEDIUM => __('Medium'),
            default => __('Low'),
        };
    }

    /**
     * Next given weekday (0 = Sunday); with $includeToday today counts.
     */
    private static function nextWeekday(Carbon $today, int $weekday, bool $includeToday): Carbon
    {
        $date = $today->copy();

        if (! $includeToday || $date->dayOfWeek !== $weekday) {
            $date = $date->next($weekday);
        }

        return $date;
    }

    private static function date(int $year, int $month, int $day): ?Carbon
    {
        return checkdate($month, $day, $year) ? Carbon::create($year, $month, $day)->startOfDay() : null;
    }
}
