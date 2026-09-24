<?php

use App\Http\Middleware\SecureCookiesOverHttps;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [SecureCookiesOverHttps::class]);

        // Shared hosts often terminate HTTPS at a front proxy. Trust only its
        // scheme header, never X-Forwarded-For, so the USSD IP allowlist
        // cannot be bypassed with a forged header.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An expired or missing session cookie on the admin console: explain
        // instead of showing a bare "419 Page Expired".
        // (Laravel turns the CSRF TokenMismatchException into an HTTP 419.)
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() === 419 && $request->is('admin', 'admin/*')) {
                return redirect()->route('admin.login')->with('error', 'Your session expired or your browser did not send the login cookie. Reload the page and try again, and open the console with https:// if the site has SSL.');
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
