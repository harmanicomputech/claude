<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coordinator;
use App\Models\PollingUnit;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CoordinatorController extends Controller
{
    public function index(): View
    {
        return view('admin.coordinators', [
            'coordinators' => Coordinator::orderByRaw('lga is not null')->orderBy('lga')->orderBy('name')->get(),
            'lgas' => PollingUnit::distinct()->orderBy('lga')->pluck('lga'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email'],
            'lga' => ['nullable', 'string', Rule::exists('polling_units', 'lga')],
        ]);

        $coordinator = Coordinator::updateOrCreate(
            ['phone_number' => PhoneNumber::normalize($validated['phone'])],
            ['name' => $validated['name'], 'email' => $validated['email'] ?? null, 'lga' => $validated['lga'] ?? null],
        );

        return back()->with('status', "Saved {$coordinator->name} (".($coordinator->lga ?? 'all LGAs').').');
    }

    public function destroy(Coordinator $coordinator): RedirectResponse
    {
        $coordinator->delete();

        return back()->with('status', "Removed {$coordinator->name}.");
    }
}
