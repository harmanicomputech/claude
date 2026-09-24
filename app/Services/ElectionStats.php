<?php

namespace App\Services;

use App\Enums\ResultStatus;
use App\Models\Agent;
use App\Models\Incident;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\Result;
use App\Support\ElectionCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Election-wide numbers for the hourly summary, the reports API and the
 * missing-PU lists. Presence only counts from the start of election day.
 */
class ElectionStats
{
    public const MISSING_PRESENCE = 'presence';

    public const MISSING_RESULTS = 'results';

    public function __construct(private ElectionCalendar $calendar) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $totalUnits = PollingUnit::count();
        $unitsWithPresence = $this->presenceQuery()->distinct()->count('polling_unit_code');
        $accepted = Result::where('status', ResultStatus::Accepted);
        $resultsReceived = (clone $accepted)->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'election' => config('election.name'),
            'polling_units' => $totalUnits,
            'presence' => [
                'polling_units' => $unitsWithPresence,
                'percent' => $this->percent($unitsWithPresence, $totalUnits),
                'agents_active' => Agent::where('is_active', true)->count(),
            ],
            'results' => [
                'polling_units' => $resultsReceived,
                'percent' => $this->percent($resultsReceived, $totalUnits),
                'pending_corrections' => Result::where('status', ResultStatus::Pending)->count(),
                'accredited_voters' => (int) (clone $accepted)->sum('accredited_voters'),
                'total_valid_votes' => (int) (clone $accepted)->sum('total_valid_votes'),
                'rejected_votes' => (int) (clone $accepted)->sum('rejected_votes'),
                'total_votes_cast' => (int) (clone $accepted)->sum('total_votes_cast'),
                'party_votes' => $this->partyTotals(),
            ],
            'incidents' => [
                'total' => Incident::count(),
                'last_hour' => Incident::where('created_at', '>=', now()->subHour())->count(),
                'by_type' => Incident::query()
                    ->select('type', DB::raw('count(*) as total'))
                    ->groupBy('type')
                    ->pluck('total', 'type')
                    ->map(fn ($total) => (int) $total)
                    ->all(),
            ],
            'by_lga' => $this->byLga(),
        ];
    }

    /**
     * Registered PUs with no presence check-in today / no accepted result.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function missing(string $type): Collection
    {
        $covered = $type === self::MISSING_PRESENCE
            ? $this->presenceQuery()->select('polling_unit_code')
            : Result::where('status', ResultStatus::Accepted)->select('polling_unit_code');

        $agents = Agent::whereNotNull('polling_unit_code')->get()->groupBy('polling_unit_code');

        return PollingUnit::whereNotIn('code', $covered)
            ->orderBy('lga')->orderBy('ward')->orderBy('code')
            ->get()
            ->map(fn (PollingUnit $unit) => [
                ...$unit->toSummaryArray(),
                'agents' => ($agents[$unit->code] ?? collect())
                    ->map(fn (Agent $agent) => $agent->toSummaryArray())
                    ->values()
                    ->all(),
            ]);
    }

    /**
     * @return array<string, int>
     */
    private function partyTotals(): array
    {
        $totals = DB::table('result_votes')
            ->join('results', 'results.id', '=', 'result_votes.result_id')
            ->where('results.status', ResultStatus::Accepted->value)
            ->groupBy('result_votes.party')
            ->select('result_votes.party', DB::raw('sum(result_votes.votes) as votes'))
            ->pluck('votes', 'party')
            ->map(fn ($votes) => (int) $votes);

        // Configured ballot order first, then anything no longer configured.
        return collect(config('election.parties'))
            ->mapWithKeys(fn (string $party) => [$party => $totals[$party] ?? 0])
            ->union($totals)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byLga(): array
    {
        $units = PollingUnit::query()
            ->select('lga', DB::raw('count(*) as total'))
            ->groupBy('lga')
            ->pluck('total', 'lga');

        $withPresence = $this->countByLga($this->presenceQuery()->select('polling_unit_code'));
        $withResults = $this->countByLga(Result::where('status', ResultStatus::Accepted)->select('polling_unit_code'));

        return $units->map(fn ($total, $lga) => [
            'lga' => $lga,
            'polling_units' => (int) $total,
            'presence' => $withPresence[$lga] ?? 0,
            'results' => $withResults[$lga] ?? 0,
            'results_percent' => $this->percent($withResults[$lga] ?? 0, (int) $total),
        ])->sortKeys()->values()->all();
    }

    /**
     * Number of registered PUs per LGA whose code appears in $codes.
     *
     * @return Collection<string, int>
     */
    private function countByLga(Builder $codes): Collection
    {
        return PollingUnit::whereIn('code', $codes)
            ->select('lga', DB::raw('count(*) as total'))
            ->groupBy('lga')
            ->pluck('total', 'lga')
            ->map(fn ($total) => (int) $total);
    }

    private function presenceQuery(): Builder
    {
        return Presence::where('confirmed_at', '>=', $this->calendar->dayStartsAt());
    }

    private function percent(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part / $whole * 100, 1);
    }
}
