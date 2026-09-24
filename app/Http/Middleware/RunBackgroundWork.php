<?php

namespace App\Http\Middleware;

use App\Support\BackgroundRunner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After the response has been sent, process waiting SMS/emails and due
 * scheduled tasks (see BackgroundRunner). Skipped when PHP can't finish the
 * response first, so callers like Africa's Talking are never kept waiting.
 */
class RunBackgroundWork
{
    public function __construct(private BackgroundRunner $runner) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (BackgroundRunner::canWorkAfterResponse()) {
            $this->runner->runAfterWebRequest();
        }
    }
}
