<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\AgentImporter;
use App\Services\AgentRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class AgentController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $agents = Agent::with('pollingUnit')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone_number', 'like', '%'.ltrim($search, '0').'%')
                ->orWhere('polling_unit_code', 'like', '%'.preg_replace('/\D/', '', $search).'%')))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('admin.agents', ['agents' => $agents, 'search' => $search]);
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

        return back()->with('status', "New PIN for {$agent->name}: {$pin}".($request->boolean('sms_pin') ? ' (texted to the agent)' : ''));
    }

    public function destroy(Agent $agent): RedirectResponse
    {
        if ($agent->results()->exists()) {
            return back()->with('error', "{$agent->name} has submitted results and cannot be removed.");
        }

        $agent->delete();

        return back()->with('status', "Removed {$agent->name}.");
    }
}
