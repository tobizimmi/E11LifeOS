<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureAddonEnabled;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ErrorReporter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'addon' => EnsureAddonEnabled::class,
            'auth.token' => AuthenticateApiToken::class,
        ]);

        $middleware->web(append: [RequireTwoFactor::class]);
        $middleware->append(SecurityHeaders::class);

        // Sign in before route model binding, so unknown tokens get 401 instead of 404.
        $middleware->prependToPriorityList(SubstituteBindings::class, AuthenticateApiToken::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Latest errors for the admin page, and an e-mail to the configured address.
        $exceptions->report(fn (Throwable $exception) => ErrorReporter::handle($exception));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The mobile app reads {success: false, error: "..."}.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/v1/*')) {
                return null;
            }

            $status = match (true) {
                $exception instanceof ValidationException => 422,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                $exception instanceof ModelNotFoundException => 404,
                default => null,
            };

            if ($status === null) {
                return null;
            }

            return response()->json(array_filter([
                'success' => false,
                'error' => $exception instanceof ValidationException ? $exception->validator->errors()->first() : ($status === 404 ? __('Not found.') : ($exception->getMessage() ?: __('Error'))),
                'errors' => $exception instanceof ValidationException ? $exception->errors() : null,
            ], fn ($value) => $value !== null), $status);
        });
    })->create();
