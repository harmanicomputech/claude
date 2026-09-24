<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only let Africa's Talking reach the USSD callback: the secret in the
 * callback URL must match USSD_CALLBACK_SECRET, and when USSD_ALLOWED_IPS is
 * set the caller's IP must be in it. Failures look like a missing page.
 */
class VerifyUssdRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('ussd.callback_secret');

        if (filled($secret) && ! hash_equals($secret, (string) $request->route('secret'))) {
            abort(404);
        }

        $allowedIps = config('ussd.allowed_ips');

        if ($allowedIps !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowedIps)) {
            abort(404);
        }

        return $next($request);
    }
}
