<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\Result;
use App\Support\Audit;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResultController extends Controller
{
    private const STATUSES = ['accepted', 'pending', 'superseded', 'rejected', 'all'];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->query($filters);

        $results = (clone $query)
            ->with('agent', 'pollingUnit', 'votes')
            ->paginate(50)
            ->withQueryString();

        return view('admin.results.index', [
            'filters' => $filters,
            'results' => $results,
            'parties' => config('election.parties'),
            'candidates' => config('election.candidates'),
            'totals' => $this->totals($query, $filters),
            'breakdown' => $this->breakdown($filters),
            'lgas' => PollingUnit::distinct()->orderBy('lga')->pluck('lga'),
            'wardsByLga' => PollingUnit::select('lga', 'ward')->distinct()->orderBy('ward')->get()->groupBy('lga')->map->pluck('ward'),
        ]);
    }

    public function show(Result $result): View
    {
        $result->load('agent', 'pollingUnit', 'votes', 'corrects');

        return view('admin.results.show', [
            'result' => $result,
            'candidates' => config('election.candidates'),
            'history' => Result::with('agent', 'votes')
                ->where('polling_unit_code', $result->polling_unit_code)
                ->oldest()
                ->get(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        Audit::record('result.exported', 'Exported results', details: array_filter($filters));
        $parties = config('election.parties');
        $timezone = config('election.timezone');

        $rows = function () use ($filters, $parties, $timezone) {
            foreach ($this->query($filters)->with('agent', 'pollingUnit', 'votes', 'corrects')->lazy(500) as $result) {
                $votes = $result->votesByParty();
                $registered = $result->pollingUnit?->registered_voters;

                yield [
                    $result->reference,
                    $result->status->value,
                    $result->polling_unit_code,
                    $result->pollingUnit?->name,
                    $result->pollingUnit?->ward,
                    $result->pollingUnit?->lga,
                    $registered,
                    $result->accredited_voters,
                    $registered ? round($result->accredited_voters / $registered * 100, 1) : '',
                    ...array_map(fn ($party) => $votes[$party] ?? 0, $parties),
                    $result->total_valid_votes,
                    $result->rejected_votes,
                    $result->total_votes_cast,
                    $result->agent->name,
                    $result->agent->phone_number,
                    $result->created_at->timezone($timezone)->format('Y-m-d H:i'),
                    $result->corrects?->reference,
                    $result->reviewed_by,
                    $result->reviewed_at?->timezone($timezone)->format('Y-m-d H:i'),
                    $result->review_note,
                ];
            }
        };

        return CsvExport::download(CsvExport::filename('results'), [
            'Reference', 'Status', 'PU code', 'Polling unit', 'Ward', 'LGA', 'Registered voters',
            'Accredited voters', 'Turnout %', ...$parties, 'Total valid', 'Rejected', 'Total cast',
            'Agent', 'Agent phone', 'Submitted', 'Corrects', 'Reviewed by', 'Reviewed at', 'Review note',
        ], $rows());
    }

    /**
     * Collation by LGA (or by ward within the chosen LGA), accepted results only.
     */
    public function exportBreakdown(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $breakdown = $this->breakdown($filters);
        $parties = config('election.parties');
        Audit::record('result.collation_exported', 'Exported collation by '.$breakdown['level'], details: array_filter($filters));

        $rows = array_map(fn (array $row) => [
            $row['area'], $row['reported'], $row['units'], $row['percent'],
            ...array_map(fn ($party) => $row['votes'][$party] ?? 0, $parties),
            $row['valid'], $row['rejected'], $row['accredited'],
        ], $breakdown['rows']);

        return CsvExport::download(
            CsvExport::filename('collation-by-'.$breakdown['level']),
            [ucfirst($breakdown['level']), 'PUs reported', 'PUs total', '% reported', ...$parties, 'Total valid', 'Rejected', 'Accredited'],
            $rows,
        );
    }

    /**
     * @return array{q: string, status: string, lga: ?string, ward: ?string, sort: string}
     */
    private function filters(Request $request): array
    {
        $status = $request->query('status', 'accepted');
        $sort = $request->query('sort', 'newest');

        return [
            'q' => trim((string) $request->query('q')),
            'status' => in_array($status, self::STATUSES, true) ? $status : 'accepted',
            'lga' => $request->query('lga') ?: null,
            'ward' => $request->query('lga') ? ($request->query('ward') ?: null) : null,
            'sort' => in_array($sort, ['newest', 'oldest', 'pu'], true) ? $sort : 'newest',
        ];
    }

    private function query(array $filters): Builder
    {
        return Result::query()
            ->when($filters['status'] !== 'all', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['lga'] || $filters['ward'], fn (Builder $query) => $query->whereIn(
                'polling_unit_code',
                PollingUnit::select('code')
                    ->when($filters['lga'], fn ($units, $lga) => $units->where('lga', $lga))
                    ->when($filters['ward'], fn ($units, $ward) => $units->where('ward', $ward)),
            ))
            ->when($filters['q'] !== '', function (Builder $query) use ($filters) {
                $term = $filters['q'];
                $digits = preg_replace('/\D/', '', $term);

                $query->where(fn (Builder $query) => $query
                    ->where('reference', 'like', '%'.strtoupper($term).'%')
                    ->when($digits !== '', fn ($query) => $query->orWhere('polling_unit_code', 'like', "%{$digits}%"))
                    ->orWhereHas('pollingUnit', fn ($units) => $units->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('agent', fn ($agents) => $agents->where('name', 'like', "%{$term}%")));
            })
            ->when($filters['sort'] === 'newest', fn ($query) => $query->latest()->latest('id'))
            ->when($filters['sort'] === 'oldest', fn ($query) => $query->oldest()->oldest('id'))
            ->when($filters['sort'] === 'pu', fn ($query) => $query->orderBy('polling_unit_code')->oldest());
    }

    /**
     * Headline figures for the filtered results.
     *
     * @return array<string, mixed>
     */
    private function totals(Builder $query, array $filters): array
    {
        $ids = (clone $query)->reorder()->select('results.id');
        $sums = (clone $query)->reorder()->toBase()
            ->selectRaw('count(*) as results, count(distinct polling_unit_code) as units, coalesce(sum(accredited_voters),0) as accredited, coalesce(sum(total_valid_votes),0) as valid, coalesce(sum(rejected_votes),0) as rejected')
            ->first();

        $partyVotes = DB::table('result_votes')
            ->whereIn('result_id', $ids)
            ->groupBy('party')
            ->selectRaw('party, sum(votes) as votes')
            ->pluck('votes', 'party');

        $unitsInScope = PollingUnit::query()
            ->when($filters['lga'], fn ($units, $lga) => $units->where('lga', $lga))
            ->when($filters['ward'], fn ($units, $ward) => $units->where('ward', $ward))
            ->count();

        $parties = collect(config('election.parties'))->mapWithKeys(fn ($party) => [$party => (int) ($partyVotes[$party] ?? 0)]);

        return [
            'results' => (int) $sums->results,
            'units' => (int) $sums->units,
            'units_in_scope' => $unitsInScope,
            'accredited' => (int) $sums->accredited,
            'valid' => (int) $sums->valid,
            'rejected' => (int) $sums->rejected,
            'parties' => $parties->all(),
            'max' => max(1, $parties->max()),
        ];
    }

    /**
     * Accepted results collated by LGA, or by ward when an LGA is chosen.
     *
     * @return array{level: string, rows: list<array<string, mixed>>}
     */
    private function breakdown(array $filters): array
    {
        $area = $filters['lga'] ? 'ward' : 'lga';

        $units = PollingUnit::query()
            ->when($filters['lga'], fn ($query, $lga) => $query->where('lga', $lga))
            ->groupBy($area)
            ->selectRaw("{$area} as area, count(*) as total")
            ->pluck('total', 'area');

        $accepted = DB::table('results')
            ->join('polling_units', 'polling_units.code', '=', 'results.polling_unit_code')
            ->where('results.status', ResultStatus::Accepted->value)
            ->when($filters['lga'], fn ($query, $lga) => $query->where('polling_units.lga', $lga));

        $figures = (clone $accepted)
            ->groupBy("polling_units.{$area}")
            ->selectRaw("polling_units.{$area} as area, count(*) as reported, sum(results.total_valid_votes) as valid, sum(results.rejected_votes) as rejected, sum(results.accredited_voters) as accredited")
            ->get()
            ->keyBy('area');

        $votes = (clone $accepted)
            ->join('result_votes', 'result_votes.result_id', '=', 'results.id')
            ->groupBy("polling_units.{$area}", 'result_votes.party')
            ->selectRaw("polling_units.{$area} as area, result_votes.party, sum(result_votes.votes) as votes")
            ->get()
            ->groupBy('area');

        $rows = [];

        foreach ($units as $name => $total) {
            $row = $figures[$name] ?? null;

            $rows[] = [
                'area' => $name,
                'units' => (int) $total,
                'reported' => (int) ($row->reported ?? 0),
                'percent' => $total ? round(($row->reported ?? 0) / $total * 100, 1) : 0,
                'votes' => ($votes[$name] ?? collect())->mapWithKeys(fn ($vote) => [$vote->party => (int) $vote->votes])->all(),
                'valid' => (int) ($row->valid ?? 0),
                'rejected' => (int) ($row->rejected ?? 0),
                'accredited' => (int) ($row->accredited ?? 0),
            ];
        }

        usort($rows, fn ($a, $b) => strnatcasecmp($a['area'], $b['area']));

        return ['level' => $area, 'rows' => $rows];
    }
}
