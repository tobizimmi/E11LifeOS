<?php

namespace App\Support;

use App\Support\Operations\AdminAlert;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Keeps the latest server errors for the admin page and e-mails them to the configured address
 * (the same error at most once an hour). Never throws itself.
 */
class ErrorReporter
{
    public const CACHE_KEY = 'errors:recent';

    /** Errors per day (errors:day:Y-m-d) for the trend on the admin overview. */
    public const DAY_KEY = 'errors:day:';

    public static function handle(Throwable $exception): void
    {
        if (self::isExpected($exception)) {
            return;
        }

        try {
            $fingerprint = md5($exception::class.$exception->getFile().$exception->getLine());
            $entry = [
                'at' => now()->toIso8601String(),
                'type' => class_basename($exception),
                'message' => mb_substr($exception->getMessage(), 0, 300),
                'where' => str_replace(base_path().'/', '', $exception->getFile()).':'.$exception->getLine(),
                'url' => app()->runningInConsole() ? 'console' : mb_substr(request()->fullUrl(), 0, 200),
            ];

            $recent = array_slice([$entry, ...Cache::get(self::CACHE_KEY, [])], 0, 20);
            Cache::put(self::CACHE_KEY, $recent, now()->addDays(14));
            Cache::add(self::DAY_KEY.now()->toDateString(), 0, now()->addDays(31));
            Cache::increment(self::DAY_KEY.now()->toDateString());

            $to = config('security.error_report_email');

            if (filled($to) && Cache::add('errors:mailed:'.$fingerprint, true, now()->addHour())) {
                Mail::raw(
                    "{$entry['type']}: {$entry['message']}\n\n{$entry['where']}\n{$entry['url']}\n{$entry['at']}\n\n".__('Further occurrences of this error are not reported for an hour.'),
                    fn ($message) => $message->to($to)->subject('['.config('app.name').'] '.__('Error').': '.$entry['type'].' – '.mb_substr($entry['message'], 0, 80)),
                );
            }

            // Push to the admins' phones as well (the same error at most once an hour).
            AdminAlert::send('error:'.$fingerprint, '🐞 '.__('Error').': '.$entry['type'], $entry['message']."\n".$entry['where'], 60, mail: false);
        } catch (Throwable) {
            // Reporting must never break the request or loop.
        }
    }

    /**
     * Errors per day of the last $days days, oldest first.
     *
     * @return array<string, int>
     */
    public static function perDay(int $days = 14): array
    {
        $counts = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = now()->subDays($offset)->toDateString();
            $counts[$day] = (int) Cache::get(self::DAY_KEY.$day, 0);
        }

        return $counts;
    }

    private static function isExpected(Throwable $exception): bool
    {
        return $exception instanceof ValidationException
            || $exception instanceof AuthenticationException
            || $exception instanceof AuthorizationException
            || $exception instanceof ModelNotFoundException
            || $exception instanceof TokenMismatchException
            || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);
    }
}
