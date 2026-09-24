<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set-up, agent management, users, settings and data clearing are for
 * admins; coordinators can see everything and review corrections.
 */
class RequireAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only admins can do this.');

        return $next($request);
    }
}
