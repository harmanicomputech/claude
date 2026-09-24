<?php

namespace App\Services;

use App\Models\Agent;
use App\Support\ElectionCalendar;
use App\Support\Rehearsal;
use Illuminate\Support\Facades\DB;

/**
 * Wipes submissions made while testing or rehearsing, keeping the polling
 * unit register, coordinators, console accounts, settings and the audit log.
 */
class TestDataCleaner
{
    public function __construct(private ElectionCalendar $calendar) {}

    /**
     * Refuse while the real election is running: windows enforced, not in
     * rehearsal mode, and inside the reporting period.
     */
    public function blockedReason(): ?string
    {
        if (config('election.enforce_windows') && ! Rehearsal::active() && $this->calendar->inReportingPeriod()) {
            return 'The election is under way, so test data cannot be cleared. (Switch on rehearsal mode or turn off ELECTION_ENFORCE_WINDOWS if this really is a test.)';
        }

        return null;
    }

    /**
     * @return array<string, int> rows removed per kind
     */
    public function clear(bool $removeAgents = false): array
    {
        return DB::transaction(function () use ($removeAgents) {
            $counts = [
                'results' => DB::table('results')->count(),
                'incidents' => DB::table('incidents')->count(),
                'check-ins' => DB::table('presences')->count(),
                'materials reports' => DB::table('material_reports')->count(),
                'dashboard events' => DB::table('dashboard_deliveries')->count(),
                'queued jobs' => DB::table('jobs')->count() + DB::table('failed_jobs')->count(),
            ];

            // Break correction links first so no self-referencing delete trips MySQL.
            DB::table('results')->update(['corrects_result_id' => null]);
            DB::table('result_votes')->delete();
            DB::table('results')->delete();
            DB::table('incidents')->delete();
            DB::table('presences')->delete();
            DB::table('material_reports')->delete();
            DB::table('dashboard_deliveries')->delete();
            DB::table('jobs')->delete();
            DB::table('failed_jobs')->delete();

            if ($removeAgents) {
                $counts['agents'] = Agent::count();
                Agent::query()->delete();
            } else {
                Agent::query()->update(['is_active' => false, 'last_seen_at' => null, 'failed_pin_attempts' => 0, 'locked_until' => null]);
            }

            return $counts;
        });
    }
}
