<?php

namespace App\Http\Controllers;

use App\Support\BackgroundRunner;
use Illuminate\Http\Response;

/**
 * GET /cron/{token}: for an external pinger (e.g. cron-job.org every
 * minute) on hosts that don't allow per-minute cron jobs.
 */
class RunnerController extends Controller
{
    public function __invoke(string $token, BackgroundRunner $runner): Response
    {
        abort_unless(hash_equals(BackgroundRunner::token(), $token), 404);

        if (BackgroundRunner::canWorkAfterResponse()) {
            // Answer the pinger straight away; the work runs after the response.
            app()->terminating(fn () => $runner->run(queueSeconds: 25, source: 'pinger'));

            return response('OK: running in the background', 200, ['Content-Type' => 'text/plain']);
        }

        $ran = $runner->run(queueSeconds: 20, source: 'pinger');

        return response($ran ? 'OK: done' : 'OK: already running', 200, ['Content-Type' => 'text/plain']);
    }
}
