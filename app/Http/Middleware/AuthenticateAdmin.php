<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the /admin console. It does not exist at all (404) unless
 * ADMIN_PASSWORD is set.
 */
class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (blank(config('election.admin_password'))) {
            abort(404);
        }

        if (! $request->session()->get('admin.authenticated')) {
            return redirect()->guest(route('admin.login'));
        }

        return $next($request);
    }
}
