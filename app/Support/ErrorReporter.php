<?php

namespace App\Support;

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

            $to = config('security.error_report_email');

            if (filled($to) && Cache::add('errors:mailed:'.$fingerprint, true, now()->addHour())) {
                Mail::raw(
                    "{$entry['type']}: {$entry['message']}\n\n{$entry['where']}\n{$entry['url']}\n{$entry['at']}\n\n".__('Further occurrences of this error are not reported for an hour.'),
                    fn ($message) => $message->to($to)->subject('['.config('app.name').'] '.__('Error').': '.$entry['type'].' – '.mb_substr($entry['message'], 0, 80)),
                );
            }
        } catch (Throwable) {
            // Reporting must never break the request or loop.
        }
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
