<?php

namespace App\Http\Controllers\Api;

use App\Enums\IncidentType;
use App\Enums\MaterialStatus;
use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Incident;
use App\Models\MaterialReport;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\Result;
use App\Support\Rehearsal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Read API for the Election Shield web app: every record the USSD service
 * holds, in the same shapes as the webhook events, with `updated_since`
 * for incremental sync and cursor pagination (ordered by updated_at, id).
 */
class DataController extends Controller
{
    private const MAX_PER_PAGE = 500;

    public function results(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(ResultStatus::class)],
            'reference' => ['nullable', 'string'],
        ]);

        $query = Result::with('agent', 'pollingUnit', 'votes', 'corrects')
            ->when($request->query('status'), fn (Builder $query, $status) => $query->where('status', $status));

        return $this->page($request, $this->byArea($request, $query), fn (Result $result) => $result->toDashboardArray());
    }

    public function result(string $reference): JsonResponse
    {
        $result = Result::where('reference', $reference)->firstOrFail();

        return response()->json(['data' => $this->withTimestamps($result, $result->toDashboardArray())]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $request->validate(['type' => ['nullable', Rule::enum(IncidentType::class)]]);

        $query = Incident::with('agent', 'pollingUnit')
            ->when($request->query('type'), fn (Builder $query, $type) => $query->where('type', $type));

        return $this->page($request, $this->byArea($request, $query), fn (Incident $incident) => $incident->toDashboardArray());
    }

    public function presences(Request $request): JsonResponse
    {
        return $this->page($request, $this->byArea($request, Presence::with('agent', 'pollingUnit')), fn (Presence $presence) => $presence->toDashboardArray());
    }

    public function materials(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(MaterialStatus::class)],
            'latest' => ['nullable', 'boolean'],
        ]);

        $query = MaterialReport::with('agent', 'pollingUnit')
            ->when($request->boolean('latest'), fn (Builder $query) => $query->whereIn('id', MaterialReport::query()->selectRaw('max(id)')->groupBy('polling_unit_code')))
            ->when($request->query('status'), fn (Builder $query, $status) => $query->where('status', $status));

        return $this->page($request, $this->byArea($request, $query), fn (MaterialReport $report) => $report->toDashboardArray());
    }

    public function pollingUnits(Request $request): JsonResponse
    {
        $query = PollingUnit::query()
            ->when($request->query('lga'), fn (Builder $query, $lga) => $query->where('lga', $lga))
            ->when($request->query('ward'), fn (Builder $query, $ward) => $query->where('ward', $ward));

        return $this->page($request, $query, fn (PollingUnit $unit) => $unit->toSummaryArray());
    }

    public function agents(Request $request): JsonResponse
    {
        $query = Agent::with('pollingUnit')
            ->when($request->query('lga'), fn (Builder $query, $lga) => $query->whereIn('polling_unit_code', PollingUnit::select('code')->where('lga', $lga)));

        return $this->page($request, $query, fn (Agent $agent) => [
            'id' => $agent->id,
            ...$agent->toSummaryArray(),
            'polling_unit' => $agent->pollingUnit?->toSummaryArray(),
            'locked' => $agent->isLocked(),
            'last_seen_at' => $agent->last_seen_at?->toIso8601String(),
        ]);
    }

    /**
     * Records at a PU / in an LGA / in a ward.
     */
    private function byArea(Request $request, Builder $query): Builder
    {
        $request->validate([
            'polling_unit' => ['nullable', 'string', 'max:30'],
            'lga' => ['nullable', 'string'],
            'ward' => ['nullable', 'string'],
        ]);

        return $query
            ->when($request->query('polling_unit'), fn (Builder $query, $code) => $query->where('polling_unit_code', PollingUnit::normalizeCode($code)))
            ->when($request->query('lga') || $request->query('ward'), fn (Builder $query) => $query->whereIn(
                'polling_unit_code',
                PollingUnit::select('code')
                    ->when($request->query('lga'), fn ($units, $lga) => $units->where('lga', $lga))
                    ->when($request->query('ward'), fn ($units, $ward) => $units->where('ward', $ward)),
            ));
    }

    /**
     * @param  callable(Model): array<string, mixed>  $present
     */
    private function page(Request $request, Builder $query, callable $present): JsonResponse
    {
        $validated = $request->validate([
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $table = $query->getModel()->getTable();

        $page = $query
            ->when($validated['updated_since'] ?? null, fn (Builder $query, $since) => $query->where("{$table}.updated_at", '>=', Carbon::parse($since)->setTimezone(config('app.timezone'))))
            ->orderBy("{$table}.updated_at")
            ->orderBy("{$table}.id")
            ->cursorPaginate((int) ($validated['per_page'] ?? 100))
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Model $model) => $this->withTimestamps($model, $present($model)))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'next_page_url' => $page->nextPageUrl(),
            'server_time' => now()->toIso8601String(),
            'rehearsal_mode' => Rehearsal::active(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withTimestamps(Model $model, array $data): array
    {
        return [
            ...$data,
            'created_at' => $model->created_at?->toIso8601String(),
            'updated_at' => $model->updated_at?->toIso8601String(),
        ];
    }
}
