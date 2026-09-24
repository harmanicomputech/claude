<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\PollingUnit;
use App\Models\Result;
use App\Services\ElectionStats;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PollingUnitController extends Controller
{
    private const STATUSES = ['all', 'reported', 'no_result', 'checked_in', 'no_presence'];

    public function __construct(private ElectionStats $stats) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $units = $this->query($filters)->paginate(50)->withQueryString();

        $scope = fn () => $this->query([...$filters, 'status' => 'all', 'q' => '']);

        return view('admin.polling-units', [
            'filters' => $filters,
            'units' => $units,
            'details' => $this->details($units->getCollection()),
            'counts' => [
                'all' => $scope()->count(),
                'reported' => $scope()->whereIn('code', $this->acceptedCodes())->count(),
                'checked_in' => $scope()->whereIn('code', $this->presenceCodes())->count(),
            ],
            'lgas' => PollingUnit::distinct()->orderBy('lga')->pluck('lga'),
            'wardsByLga' => PollingUnit::select('lga', 'ward')->distinct()->orderBy('ward')->get()->groupBy('lga')->map->pluck('ward'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $timezone = config('election.timezone');

        $rows = function () use ($filters, $timezone) {
            foreach ($this->query($filters)->lazy(500)->chunk(500) as $chunk) {
                $chunk = $chunk->collect();
                $details = $this->details($chunk);

                foreach ($chunk as $unit) {
                    $detail = $details[$unit->code];

                    yield [
                        $unit->code,
                        $unit->name,
                        $unit->ward,
                        $unit->lga,
                        $unit->registered_voters,
                        $detail['agents']->map(fn ($agent) => "{$agent->name} {$agent->phone_number}")->implode('; '),
                        $detail['checked_in_at']?->timezone($timezone)->format('Y-m-d H:i'),
                        $detail['result']?->reference,
                        $detail['result']?->total_valid_votes,
                        $detail['result']?->created_at->timezone($timezone)->format('Y-m-d H:i'),
                    ];
                }
            }
        };

        return CsvExport::download(CsvExport::filename('polling-units'), [
            'PU code', 'Polling unit', 'Ward', 'LGA', 'Registered voters', 'Agents', 'Checked in', 'Result reference', 'Total valid', 'Result submitted',
        ], $rows());
    }

    /**
     * Agents, latest check-in and accepted result for each unit, keyed by code.
     *
     * @param  Collection<int, PollingUnit>  $units
     * @return array<string, array{agents: Collection, checked_in_at: ?Carbon, result: ?Result}>
     */
    private function details(Collection $units): array
    {
        $codes = $units->pluck('code');

        $agents = Agent::whereIn('polling_unit_code', $codes)->orderBy('name')->get()->groupBy('polling_unit_code');
        $presence = $this->stats->presenceQuery()->whereIn('polling_unit_code', $codes)
            ->selectRaw('polling_unit_code, max(confirmed_at) as confirmed_at')
            ->groupBy('polling_unit_code')
            ->pluck('confirmed_at', 'polling_unit_code');
        $results = Result::where('status', ResultStatus::Accepted)->whereIn('polling_unit_code', $codes)->get()->keyBy('polling_unit_code');

        return $units->mapWithKeys(fn (PollingUnit $unit) => [$unit->code => [
            'agents' => $agents[$unit->code] ?? collect(),
            'checked_in_at' => isset($presence[$unit->code]) ? Carbon::parse($presence[$unit->code]) : null,
            'result' => $results[$unit->code] ?? null,
        ]])->all();
    }

    /**
     * @return array{q: string, lga: ?string, ward: ?string, status: string}
     */
    private function filters(Request $request): array
    {
        $status = $request->query('status', 'all');

        return [
            'q' => trim((string) $request->query('q')),
            'lga' => $request->query('lga') ?: null,
            'ward' => $request->query('lga') ? ($request->query('ward') ?: null) : null,
            'status' => in_array($status, self::STATUSES, true) ? $status : 'all',
        ];
    }

    private function query(array $filters): Builder
    {
        return PollingUnit::query()
            ->when($filters['lga'], fn (Builder $query, $lga) => $query->where('lga', $lga))
            ->when($filters['ward'], fn (Builder $query, $ward) => $query->where('ward', $ward))
            ->when($filters['q'] !== '', function (Builder $query) use ($filters) {
                $digits = preg_replace('/\D/', '', $filters['q']);

                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', "%{$filters['q']}%")
                    ->when($digits !== '', fn ($query) => $query->orWhere('code', 'like', "%{$digits}%")));
            })
            ->when($filters['status'] === 'reported', fn (Builder $query) => $query->whereIn('code', $this->acceptedCodes()))
            ->when($filters['status'] === 'no_result', fn (Builder $query) => $query->whereNotIn('code', $this->acceptedCodes()))
            ->when($filters['status'] === 'checked_in', fn (Builder $query) => $query->whereIn('code', $this->presenceCodes()))
            ->when($filters['status'] === 'no_presence', fn (Builder $query) => $query->whereNotIn('code', $this->presenceCodes()))
            ->orderBy('lga')->orderBy('ward')->orderBy('code');
    }

    private function acceptedCodes(): Builder
    {
        return Result::where('status', ResultStatus::Accepted)->select('polling_unit_code');
    }

    private function presenceCodes(): Builder
    {
        return $this->stats->presenceQuery()->select('polling_unit_code');
    }
}
