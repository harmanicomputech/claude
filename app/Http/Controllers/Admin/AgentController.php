<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\AgentImporter;
use App\Services\AgentRegistrar;
use App\Services\ElectionStats;
use App\Support\Audit;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentController extends Controller
{
    private const STATUSES = ['all', 'checked_in', 'not_checked_in', 'locked'];

    public function __construct(private ElectionStats $stats) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('admin.agents', [
            'agents' => $this->query($filters)->with('pollingUnit')->paginate(50)->withQueryString(),
            'search' => $filters['q'],
            'filters' => $filters,
            'checkedIn' => $this->checkedInAt($filters),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $timezone = config('election.timezone');
        $checkedIn = $this->checkedInAt($filters);
        Audit::record('agent.exported', 'Exported agents', details: array_filter($filters));

        $rows = function () use ($filters, $timezone, $checkedIn) {
            foreach ($this->query($filters)->with('pollingUnit')->withCount('results', 'incidents')->lazy(500) as $agent) {
                yield [
                    $agent->name,
                    $agent->phone_number,
                    $agent->polling_unit_code,
                    $agent->pollingUnit?->name,
                    $agent->pollingUnit?->ward,
                    $agent->pollingUnit?->lga,
                    isset($checkedIn[$agent->id]) ? Carbon::parse($checkedIn[$agent->id])->timezone($timezone)->format('Y-m-d H:i') : '',
                    $agent->results_count,
                    $agent->incidents_count,
                    $agent->isLocked() ? 'yes' : 'no',
                ];
            }
        };

        return CsvExport::download(CsvExport::filename('agents'), [
            'Name', 'Phone', 'Assigned PU', 'Polling unit', 'Ward', 'LGA', 'Checked in', 'Results submitted', 'Incidents reported', 'Locked',
        ], $rows());
    }

    /**
     * @return array{q: string, status: string}
     */
    private function filters(Request $request): array
    {
        $status = $request->query('status', 'all');

        return [
            'q' => trim((string) $request->query('q')),
            'status' => in_array($status, self::STATUSES, true) ? $status : 'all',
        ];
    }

    private function query(array $filters): Builder
    {
        $search = $filters['q'];
        $checkedIn = fn () => $this->stats->presenceQuery()->select('agent_id');

        return Agent::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone_number', 'like', '%'.ltrim($search, '0').'%')
                ->orWhere('polling_unit_code', 'like', '%'.preg_replace('/\D/', '', $search).'%')))
            ->when($filters['status'] === 'checked_in', fn ($query) => $query->whereIn('id', $checkedIn()))
            ->when($filters['status'] === 'not_checked_in', fn ($query) => $query->whereNotIn('id', $checkedIn()))
            ->when($filters['status'] === 'locked', fn ($query) => $query->where('locked_until', '>', now()))
            ->orderBy('name');
    }

    /**
     * Latest counted check-in per agent id.
     *
     * @return Collection<int, string>
     */
    private function checkedInAt(array $filters): Collection
    {
        return $this->stats->presenceQuery()
            ->selectRaw('agent_id, max(confirmed_at) as confirmed_at')
            ->groupBy('agent_id')
            ->pluck('confirmed_at', 'agent_id');
    }

    public function store(Request $request, AgentRegistrar $registrar): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'pu_code' => ['nullable', 'string', 'max:30'],
            'pin' => ['nullable', 'digits:4'],
            'sms_pin' => ['nullable', 'boolean'],
        ]);

        try {
            [$agent, $pin] = $registrar->register(
                $validated['phone'],
                $validated['name'],
                $validated['pu_code'] ?? null,
                $validated['pin'] ?? null,
                $request->boolean('sms_pin'),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        Audit::record($agent->wasRecentlyCreated ? 'agent.created' : 'agent.updated', "Saved agent {$agent->name} ({$agent->phone_number})".($agent->polling_unit_code ? " for PU {$agent->polling_unit_code}" : ''), $agent, ['pin_set' => $pin !== null, 'pin_texted' => $request->boolean('sms_pin')]);

        return back()->with('status', "Saved {$agent->name} ({$agent->phone_number})."
            .($pin !== null ? " PIN: {$pin}" : ' PIN unchanged.'));
    }

    public function import(Request $request, AgentImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'sms_pins' => ['nullable', 'boolean'],
        ]);

        $report = $importer->import($request->file('file')->getRealPath(), $request->boolean('sms_pins'));

        Audit::record('agent.imported', 'Imported '.count($report['imported']).' agent(s) from '.$request->file('file')->getClientOriginalName(), details: ['skipped' => count($report['errors']), 'pins_texted' => $request->boolean('sms_pins')]);

        return back()
            ->with(count($report['errors']) ? 'error' : 'status', count($report['imported']).' agent(s) imported, '.count($report['errors']).' row(s) skipped.')
            ->with('import', $report);
    }

    public function resetPin(Request $request, Agent $agent, AgentRegistrar $registrar): RedirectResponse
    {
        $validated = $request->validate([
            'pin' => ['nullable', 'digits:4'],
            'sms_pin' => ['nullable', 'boolean'],
        ]);

        $pin = $registrar->resetPin($agent, $validated['pin'] ?? null, $request->boolean('sms_pin'));
        Audit::record('agent.pin_reset', "Reset PIN and unlocked {$agent->name} ({$agent->phone_number})", $agent, ['pin_texted' => $request->boolean('sms_pin')]);

        return back()->with('status', "New PIN for {$agent->name}: {$pin}".($request->boolean('sms_pin') ? ' (texted to the agent)' : ''));
    }

    public function destroy(Agent $agent): RedirectResponse
    {
        if ($agent->results()->exists()) {
            return back()->with('error', "{$agent->name} has submitted results and cannot be removed.");
        }

        $agent->delete();
        Audit::record('agent.deleted', "Removed agent {$agent->name} ({$agent->phone_number})");

        return back()->with('status', "Removed {$agent->name}.");
    }
}
