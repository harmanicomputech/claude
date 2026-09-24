<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the coordinator API (ELECTION_API_TOKEN).
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('election.api_token');

        if (blank($token)) {
            abort(503, 'The coordinator API is disabled. Set ELECTION_API_TOKEN to enable it.');
        }

        if (! hash_equals($token, (string) $request->bearerToken())) {
            abort(401, 'Invalid API token.');
        }

        return $next($request);
    }
}
