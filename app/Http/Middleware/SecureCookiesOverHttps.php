<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mark session cookies "secure" exactly when the page was served over HTTPS.
 *
 * A fixed SESSION_SECURE_COOKIE=true breaks every form with a 419 when the
 * site is opened over plain http (e.g. before SSL is set up), because the
 * browser never sends the cookie back; false would leak it over http.
 */
class SecureCookiesOverHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['session.secure' => $request->isSecure()]);

        return $next($request);
    }
}
