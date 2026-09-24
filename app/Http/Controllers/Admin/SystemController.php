<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Queues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Stand-ins for the artisan commands on hosting without a terminal.
 */
class SystemController extends Controller
{
    public function migrate(): RedirectResponse
    {
        return $this->run('migrate', ['--force' => true], 'Database set up');
    }

    public function importPollingUnits(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['nullable', 'file', 'mimes:csv,txt', 'max:10240']]);

        $path = $request->file('file')?->getRealPath() ?? database_path('data/ebonyi_polling_units.csv');

        return $this->run('pu:import', ['file' => $path], 'Polling units imported');
    }

    public function testEmail(Request $request): RedirectResponse
    {
        $validated = $request->validate(['to' => ['nullable', 'email']]);

        return $this->run('election:test-email', array_filter(['to' => $validated['to'] ?? null]), 'Test email sent');
    }

    /**
     * Work the queue right now (what the cron job does every minute), so
     * emails and SMS go out and any error shows up immediately.
     */
    public function runJobs(): RedirectResponse
    {
        return $this->run('queue:work', ['--queue' => Queues::WORKER_ORDER, '--stop-when-empty' => true, '--max-time' => 20, '--tries' => 3], 'Background jobs processed');
    }

    public function retryFailedJobs(): RedirectResponse
    {
        return $this->run('queue:retry', ['id' => ['all']], 'Failed jobs queued again: press "Run background jobs now" to send them');
    }

    public function sendSummary(): RedirectResponse
    {
        return $this->run('election:summary', [], 'Summary email queued');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function run(string $command, array $arguments, string $success): RedirectResponse
    {
        try {
            $exitCode = Artisan::call($command, $arguments);
            $output = trim(Artisan::output());
        } catch (Throwable $e) {
            report($e);
            Audit::record('system.'.$command, "{$success}: failed ({$e->getMessage()})");

            return back()->with('error', "{$command} failed: {$e->getMessage()}");
        }

        Audit::record('system.'.$command, $exitCode === 0 ? $success : "{$success}: reported problems");

        return back()
            ->with($exitCode === 0 ? 'status' : 'error', $exitCode === 0 ? $success : "{$command} reported problems")
            ->with('output', $output);
    }
}
