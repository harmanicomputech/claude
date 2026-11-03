<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('admin.users', [
            'users' => User::orderBy('role')->orderBy('name')->get(),
            'roles' => UserRole::cases(),
            'places' => PollingUnit::query()->select('lga', 'ward')->distinct()->orderBy('lga')->orderBy('ward')->get()->groupBy('lga')->map->pluck('ward'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'area' => ['nullable', 'string', 'max:220'],
        ]);

        $password = $this->temporaryPassword();

        $user = User::create([...collect($validated)->except('area')->all(), ...$this->area($validated['area'] ?? null), 'email' => strtolower($validated['email']), 'password' => $password]);

        Audit::record('user.created', "Created {$user->role->label()} account for {$user->name} ({$user->email})", $user);

        return back()->with('status', "Account created for {$user->name}. Temporary password: {$password}. Share it privately; it is shown only now.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::enum(UserRole::class)], 'area' => ['nullable', 'string', 'max:220']]);
        $role = UserRole::from($validated['role']);

        if ($user->isAdmin() && $role !== UserRole::Admin && $this->isLastAdmin($user)) {
            return back()->with('error', 'There must always be at least one admin.');
        }

        $user->update(['role' => $role, ...$this->area($validated['area'] ?? null)]);
        Audit::record('user.role_changed', "Changed {$user->name}'s role to {$role->label()} ({$user->areaLabel()})", $user);

        return back()->with('status', "{$user->name} is now a {$role->label()}: {$user->areaLabel()}.");
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $password = $this->temporaryPassword();
        $user->update(['password' => $password]);

        Audit::record('user.password_reset', "Reset {$user->name}'s password", $user);

        return back()->with('status', "New temporary password for {$user->name}: {$password}. Share it privately; it is shown only now.");
    }

    public function changeOwnPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        $request->user()->update(['password' => $validated['password']]);
        Audit::record('user.password_changed', 'Changed own password', $request->user());

        return back()->with('status', 'Your password has been changed.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot remove your own account.');
        }

        if ($user->isAdmin() && $this->isLastAdmin($user)) {
            return back()->with('error', 'There must always be at least one admin.');
        }

        $user->delete();
        Audit::record('user.deleted', "Removed {$user->name}'s account ({$user->email})");

        return back()->with('status', "Removed {$user->name}.");
    }

    /**
     * "Abakaliki|" (whole LGA) or "Abakaliki|Abakpa" (a ward), checked against the register.
     *
     * @return array{lga: ?string, ward: ?string}
     */
    private function area(?string $value): array
    {
        [$lga, $ward] = array_pad(explode('|', (string) $value, 2), 2, '');

        if ($lga === '' || ! PollingUnit::where('lga', $lga)->when($ward !== '', fn ($q) => $q->where('ward', $ward))->exists()) {
            return ['lga' => null, 'ward' => null];
        }

        return ['lga' => $lga, 'ward' => $ward === '' ? null : $ward];
    }

    private function isLastAdmin(User $user): bool
    {
        return User::where('role', UserRole::Admin)->whereKeyNot($user->id)->doesntExist();
    }

    private function temporaryPassword(): string
    {
        return Str::password(14, symbols: false);
    }
}
