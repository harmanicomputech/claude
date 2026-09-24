<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Coordinator;
use App\Models\PollingUnit;
use App\Services\AgentRegistrar;
use App\Support\Audit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable instruction cards for agents, optionally with freshly set PINs
 * (PINs are stored hashed, so existing ones can never be printed).
 */
class AgentCardController extends Controller
{
    /** Hashing PINs is deliberately slow; keep a batch well inside PHP's time limit. */
    private const PER_PAGE = 60;

    public function index(Request $request): View
    {
        return $this->cards($request, $this->agents($request), []);
    }

    public function withNewPins(Request $request, AgentRegistrar $registrar): View
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm that the old PINs will stop working.']);

        $agents = $this->agents($request);
        $pins = [];

        foreach ($agents as $agent) {
            $pins[$agent->id] = $registrar->resetPin($agent);
        }

        Audit::record('agent.cards_with_pins', 'Printed cards with new PINs for '.count($pins).' agent(s)', details: [
            'filters' => array_filter($request->only('lga', 'ward', 'q')),
            'page' => $agents->currentPage(),
            'agents' => $agents->pluck('phone_number')->all(),
        ]);

        return $this->cards($request, $agents, $pins);
    }

    /**
     * @param  array<int, string>  $pins  agent id => new PIN
     */
    private function cards(Request $request, LengthAwarePaginator $agents, array $pins): View
    {
        $coordinators = Coordinator::orderBy('name')->get();

        return view('admin.agent-cards', [
            'agents' => $agents,
            'pins' => $pins,
            'filters' => $request->only('lga', 'ward', 'q'),
            'lgas' => PollingUnit::distinct()->orderBy('lga')->pluck('lga'),
            'coordinatorFor' => fn (?string $lga) => $coordinators->firstWhere('lga', $lga) ?? $coordinators->firstWhere('lga', null),
        ]);
    }

    private function agents(Request $request): LengthAwarePaginator
    {
        $search = trim((string) $request->input('q'));

        return Agent::with('pollingUnit')
            ->when($request->input('lga'), fn (Builder $query, $lga) => $query->whereIn('polling_unit_code', PollingUnit::select('code')->where('lga', $lga)))
            ->when($search !== '', fn (Builder $query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone_number', 'like', '%'.ltrim($search, '0').'%')
                ->orWhere('polling_unit_code', 'like', '%'.preg_replace('/\D/', '', $search).'%')))
            ->orderBy('polling_unit_code')
            ->orderBy('name')
            ->paginate(self::PER_PAGE, page: (int) $request->input('page', 1))
            ->withQueryString();
    }
}
