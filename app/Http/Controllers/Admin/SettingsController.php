<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Incident;
use App\Models\Presence;
use App\Models\Result;
use App\Services\DashboardClient;
use App\Services\DashboardOutbox;
use App\Services\TestDataCleaner;
use App\Support\Audit;
use App\Support\ElectionCalendar;
use App\Support\Rehearsal;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(ElectionCalendar $calendar, TestDataCleaner $cleaner): View
    {
        return view('admin.settings', [
            'rehearsal' => Rehearsal::active(),
            'dashboardUrl' => config('services.dashboard.url'),
            'calendar' => $calendar,
            'clearBlocked' => $cleaner->blockedReason(),
            'counts' => [
                'results' => Result::count(),
                'incidents' => Incident::count(),
                'check-ins' => Presence::count(),
                'agents' => Agent::count(),
            ],
        ]);
    }

    public function rehearsal(Request $request): RedirectResponse
    {
        $on = $request->boolean('on');
        Settings::set(Rehearsal::SETTING, $on);

        Audit::record('settings.rehearsal', $on ? 'Switched rehearsal mode ON' : 'Switched rehearsal mode OFF');

        return back()->with('status', $on
            ? 'Rehearsal mode is ON: submission windows are open and every SMS, email and USSD menu is labelled REHEARSAL.'
            : 'Rehearsal mode is OFF.');
    }

    public function backfillDashboard(DashboardClient $dashboard, DashboardOutbox $outbox): RedirectResponse
    {
        if (! $dashboard->enabled()) {
            return back()->with('error', 'Set DASHBOARD_WEBHOOK_URL in .env first.');
        }

        $queued = $outbox->backfill();
        Audit::record('dashboard.backfilled', "Sent existing data to the dashboard ({$queued} new event(s))");

        return back()->with('status', "{$queued} event(s) queued for the dashboard. Anything it already had was skipped.");
    }

    public function clearTestData(Request $request, TestDataCleaner $cleaner): RedirectResponse
    {
        $request->validate([
            'confirm' => ['required', 'in:CLEAR'],
            'password' => ['required', 'current_password'],
        ], [
            'confirm.in' => 'Type CLEAR (in capitals) to confirm.',
            'password.current_password' => 'Your password is wrong.',
        ]);

        if ($reason = $cleaner->blockedReason()) {
            return back()->with('error', $reason);
        }

        $counts = $cleaner->clear($request->boolean('remove_agents'));
        $summary = collect($counts)->map(fn ($count, $kind) => number_format($count)." {$kind}")->implode(', ');

        Audit::record('settings.test_data_cleared', "Cleared test data: {$summary}", details: $counts);

        return back()->with('status', "Test data cleared: {$summary}. Polling units, coordinators and console accounts were kept.");
    }
}
