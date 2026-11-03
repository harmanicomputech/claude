<?php

namespace App\Http\Middleware;

use App\Support\Area;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Coordinators see only their LGA or ward in the console (see Area).
 */
class LimitToArea
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $user ? Area::limitTo($user) : Area::clear();
        View::share('areaLabel', Area::label());

        try {
            $response = $next($request);
        } finally {
            Area::clear();
        }

        // Downloads are written after this returns: keep the area while they are.
        if ($user && $response instanceof StreamedResponse && ($callback = $response->getCallback()) !== null) {
            $response->setCallback(function () use ($user, $callback) {
                Area::limitTo($user);
                try {
                    $callback();
                } finally {
                    Area::clear();
                }
            });
        }

        return $response;
    }
}
