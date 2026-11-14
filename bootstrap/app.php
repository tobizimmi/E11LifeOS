<?php

use App\Http\Middleware\ApplyUrlPrefix;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureAddonEnabled;
use App\Http\Middleware\EnsureModuleAllowed;
use App\Http\Middleware\LogSensitiveAccess;
use App\Http\Middleware\RecordSlowRequests;
use App\Http\Middleware\RequireApiConfirmation;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ScanUploads;
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
        // The app always sits behind the Apache/Plesk reverse proxy on the same host (see
        // docs/deployment.md) — without this, request()->ip() is the proxy's own loopback
        // address for every visitor, which would make the per-IP login/register rate limits
        // apply to the whole app at once instead of per visitor.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->alias([
            'addon' => EnsureAddonEnabled::class,
            'module' => EnsureModuleAllowed::class,
            'auth.token' => AuthenticateApiToken::class,
            'sensitive.log' => LogSensitiveAccess::class,
            'api.confirmed' => RequireApiConfirmation::class,
        ]);

        // Uploads are checked for viruses after the session started, so errors reach the form.
        // Answers from push notifications come from the service worker without a form token.
        $middleware->validateCsrfTokens(except: ['push-actions/*']);
        $middleware->web(append: [RequireTwoFactor::class, EnsureModuleAllowed::class, ScanUploads::class, ApplyUrlPrefix::class, RecordSlowRequests::class]);
        $middleware->api(append: [ScanUploads::class]);
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
