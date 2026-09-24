<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IncidentType;
use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\PollingUnit;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('admin.incidents', [
            'filters' => $filters,
            'incidents' => $this->query($filters)->with('agent', 'pollingUnit')->paginate(50)->withQueryString(),
            'counts' => $this->query([...$filters, 'type' => null])->reorder()
                ->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type'),
            'types' => IncidentType::cases(),
            'lgas' => PollingUnit::distinct()->orderBy('lga')->pluck('lga'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $timezone = config('election.timezone');

        $rows = function () use ($filters, $timezone) {
            foreach ($this->query($filters)->with('agent', 'pollingUnit')->lazy(500) as $incident) {
                yield [
                    $incident->reference,
                    $incident->type->label(),
                    $incident->isUrgent() ? 'yes' : 'no',
                    $incident->note,
                    $incident->polling_unit_code,
                    $incident->pollingUnit?->name,
                    $incident->pollingUnit?->ward,
                    $incident->pollingUnit?->lga,
                    $incident->agent->name,
                    $incident->agent->phone_number,
                    $incident->created_at->timezone($timezone)->format('Y-m-d H:i'),
                ];
            }
        };

        return CsvExport::download(CsvExport::filename('incidents'), [
            'Reference', 'Type', 'Urgent', 'Note', 'PU code', 'Polling unit', 'Ward', 'LGA', 'Agent', 'Agent phone', 'Reported',
        ], $rows());
    }

    /**
     * @return array{q: string, type: ?string, lga: ?string}
     */
    private function filters(Request $request): array
    {
        $type = IncidentType::tryFrom((string) $request->query('type'));

        return [
            'q' => trim((string) $request->query('q')),
            'type' => $type?->value,
            'lga' => $request->query('lga') ?: null,
        ];
    }

    private function query(array $filters): Builder
    {
        return Incident::query()
            ->when($filters['type'], fn (Builder $query, $type) => $query->where('type', $type))
            ->when($filters['lga'], fn (Builder $query, $lga) => $query->whereIn('polling_unit_code', PollingUnit::select('code')->where('lga', $lga)))
            ->when($filters['q'] !== '', function (Builder $query) use ($filters) {
                $term = $filters['q'];
                $digits = preg_replace('/\D/', '', $term);

                $query->where(fn (Builder $query) => $query
                    ->where('reference', 'like', '%'.strtoupper($term).'%')
                    ->orWhere('note', 'like', "%{$term}%")
                    ->when($digits !== '', fn ($query) => $query->orWhere('polling_unit_code', 'like', "%{$digits}%"))
                    ->orWhereHas('pollingUnit', fn ($units) => $units->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('agent', fn ($agents) => $agents->where('name', 'like', "%{$term}%")));
            })
            ->latest()
            ->latest('id');
    }
}
