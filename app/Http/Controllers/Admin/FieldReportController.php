<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MaterialStatus;
use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Services\ElectionStats;
use App\Support\Audit;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Agents' check-ins (presence) and materials reports, with the INEC
 * personnel, newest first. Coordinators see only their LGA or ward.
 */
class FieldReportController extends Controller
{
    private const MATERIALS = ['all', 'arrived', 'incomplete', 'not_arrived', 'personnel_missing'];

    public function __construct(private ElectionStats $stats) {}

    public function checkins(Request $request): View
    {
        $filters = $this->filters($request);

        return view('admin.field-reports', [
            'tab' => 'checkins',
            'filters' => $filters,
            'rows' => $this->checkinQuery($filters)->with('agent', 'pollingUnit')->paginate(50)->withQueryString(),
            ...$this->common($filters),
        ]);
    }

    public function materials(Request $request): View
    {
        $filters = $this->filters($request);
        $latest = fn () => $this->materialsQuery([...$filters, 'status' => 'all']);

        return view('admin.field-reports', [
            'tab' => 'materials',
            'filters' => $filters,
            'rows' => $this->materialsQuery($filters)->with('agent', 'pollingUnit')->paginate(50)->withQueryString(),
            'statusCounts' => [
                'all' => $latest()->count(),
                'arrived' => $latest()->where('status', MaterialStatus::Arrived)->count(),
                'incomplete' => $latest()->where('status', MaterialStatus::Incomplete)->count(),
                'not_arrived' => $latest()->where('status', MaterialStatus::NotArrived)->count(),
                'personnel_missing' => $latest()->where('personnel_arrived', false)->count(),
            ],
            ...$this->common($filters),
        ]);
    }

    public function export(Request $request, string $tab): StreamedResponse
    {
        $filters = $this->filters($request);
        $timezone = config('election.timezone');
        Audit::record('field_report.exported', 'Exported '.($tab === 'materials' ? 'materials reports' : 'check-ins'), details: array_filter($filters));

        if ($tab === 'materials') {
            $rows = function () use ($filters, $timezone) {
                foreach ($this->materialsQuery($filters)->with('agent', 'pollingUnit')->lazy(500) as $report) {
                    yield [$report->polling_unit_code, $report->pollingUnit?->name, $report->pollingUnit?->ward, $report->pollingUnit?->lga,
                        $report->status->label(), $report->personnelLabel(), $report->agent?->name, $report->agent?->phone_number, $report->channel,
                        $report->reported_at->timezone($timezone)->format('Y-m-d H:i')];
                }
            };

            return CsvExport::download(CsvExport::filename('materials'), ['PU code', 'Polling unit', 'Ward', 'LGA', 'Materials', 'Personnel', 'Agent', 'Phone', 'Channel', 'Reported'], $rows());
        }

        $rows = function () use ($filters, $timezone) {
            foreach ($this->checkinQuery($filters)->with('agent', 'pollingUnit')->lazy(500) as $presence) {
                yield [$presence->polling_unit_code, $presence->pollingUnit?->name, $presence->pollingUnit?->ward, $presence->pollingUnit?->lga,
                    $presence->agent?->name, $presence->agent?->phone_number, $presence->channel, $presence->confirmed_at->timezone($timezone)->format('Y-m-d H:i')];
            }
        };

        return CsvExport::download(CsvExport::filename('check-ins'), ['PU code', 'Polling unit', 'Ward', 'LGA', 'Agent', 'Phone', 'Channel', 'Checked in'], $rows());
    }

    /**
     * @return array<string, mixed>
     */
    private function common(array $filters): array
    {
        return [
            'units' => $this->area(PollingUnit::query(), $filters, 'code')->count(),
            'checkedIn' => $this->checkinQuery([...$filters, 'q' => ''])->distinct()->count('polling_unit_code'),
            'reported' => $this->materialsQuery([...$filters, 'q' => '', 'status' => 'all'])->count(),
            'lgas' => PollingUnit::distinct()->orderBy('lga')->pluck('lga'),
            'wards' => $filters['lga'] ? PollingUnit::where('lga', $filters['lga'])->distinct()->orderBy('ward')->pluck('ward') : collect(),
        ];
    }

    /**
     * @return array{q: string, lga: ?string, ward: ?string, status: string}
     */
    private function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');

        return [
            'q' => trim((string) $request->query('q')),
            'lga' => $request->query('lga') ?: null,
            'ward' => $request->query('lga') ? ($request->query('ward') ?: null) : null,
            'status' => in_array($status, self::MATERIALS, true) ? $status : 'all',
        ];
    }

    private function checkinQuery(array $filters): Builder
    {
        return $this->search($this->area($this->stats->presenceQuery(), $filters), $filters)->latest('confirmed_at')->latest('id');
    }

    private function materialsQuery(array $filters): Builder
    {
        return $this->search($this->area($this->stats->latestMaterialsQuery(), $filters), $filters)
            ->when(in_array($filters['status'], ['arrived', 'incomplete', 'not_arrived'], true), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['status'] === 'personnel_missing', fn (Builder $query) => $query->where('personnel_arrived', false))
            ->latest('reported_at')->latest('id');
    }

    private function area(Builder $query, array $filters, string $column = 'polling_unit_code'): Builder
    {
        $units = fn () => PollingUnit::query()->select('code')
            ->when($filters['lga'], fn ($q, $lga) => $q->where('lga', $lga))
            ->when($filters['ward'], fn ($q, $ward) => $q->where('ward', $ward));

        return $query->when($filters['lga'], fn (Builder $q) => $column === 'code'
            ? $q->where('lga', $filters['lga'])->when($filters['ward'], fn ($q, $ward) => $q->where('ward', $ward))
            : $q->whereIn($column, $units()));
    }

    private function search(Builder $query, array $filters): Builder
    {
        $term = $filters['q'];
        if ($term === '') {
            return $query;
        }
        $digits = preg_replace('/\D/', '', $term);

        return $query->where(fn (Builder $query) => $query
            ->when($digits !== '', fn ($query) => $query->orWhere('polling_unit_code', 'like', "%{$digits}%"))
            ->orWhereHas('pollingUnit', fn ($units) => $units->where('name', 'like', "%{$term}%"))
            ->orWhereHas('agent', fn ($agents) => $agents->where('name', 'like', "%{$term}%")));
    }
}
