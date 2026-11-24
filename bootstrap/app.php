<?php

use App\Commerce\Api\QuoteApiResponse;
use App\Commerce\CartJsonErrors;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Http\Middleware\QuoteApiBoundary;
use App\Http\Middleware\RecordUserActivity;
use App\Http\Middleware\ValidateImpersonation;
use App\Support\ImpersonationManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(QuoteApiBoundary::class);
        // Preserve the customer's literal spelling, accents and whitespace.
        $middleware->trimStrings(except: ['seller_name']);
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'session.current' => EnsureSessionIsCurrent::class,
            'impersonation' => ValidateImpersonation::class,
        ]);
        $middleware->appendToGroup('web', RecordUserActivity::class);
        $middleware->redirectGuestsTo(function (Request $request): string {
            $impersonation = app(ImpersonationManager::class);
            if ($impersonation->active() && ! $impersonation->target()) {
                $canRestoreActor = $impersonation->actorSessionIsCurrent();
                $impersonation->finish($request, 'target_unavailable', $canRestoreActor);
                $request->session()->flash(
                    'warning',
                    $canRestoreActor
                        ? 'La cuenta objetivo ya no existe; volviste a tu sesión administrativa.'
                        : 'La cuenta objetivo ya no existe y la sesión administrativa dejó de estar autorizada.',
                );

                return $canRestoreActor ? route('admin.dashboard') : route('admin.login');
            }

            return route('admin.login');
        });
        $middleware->redirectUsersTo(fn (Request $request): string => route('admin.profile.edit'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $error) {
            // The API boundary records only whitelisted technical metadata.
            if (app()->bound('request') && QuoteApiResponse::isApi(request())) {
                return false;
            }
        });
        $exceptions->render(fn (Throwable $error, Request $request) => QuoteApiResponse::render($error, $request));
        $exceptions->render(fn (Throwable $error, Request $request) => CartJsonErrors::render($error, $request));
    })->create();
