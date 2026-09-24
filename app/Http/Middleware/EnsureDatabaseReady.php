<?php

namespace App\Http\Middleware;

use App\Support\SystemStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin pages that read election data need the database set up first.
 */
class EnsureDatabaseReady
{
    public function __construct(private SystemStatus $status) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->status->isReady()) {
            return redirect()->route('admin.overview')
                ->with('error', 'Set up the database first (Overview → "Set up / update database").');
        }

        return $next($request);
    }
}
