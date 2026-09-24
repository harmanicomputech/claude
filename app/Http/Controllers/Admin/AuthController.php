<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\SystemStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

/**
 * Console login. On a fresh install there are no accounts yet: the first
 * admin is created with ADMIN_PASSWORD from .env as a one-time setup key.
 */
class AuthController extends Controller
{
    public function __construct(private SystemStatus $status) {}

    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.overview');
        }

        if (! $this->status->databaseReachable()) {
            return view('admin.login', ['mode' => 'no-database']);
        }

        if (! $this->hasUsers()) {
            abort_if(blank(config('election.admin_password')), 404);

            return view('admin.login', ['mode' => 'setup']);
        }

        return view('admin.login', ['mode' => 'login']);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            Audit::record('auth.failed', "Failed login for {$credentials['email']}", details: ['email' => $credentials['email']], actor: 'Unknown');

            return back()->withInput($request->only('email'))->withErrors(['email' => 'Wrong email or password.']);
        }

        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();
        Audit::record('auth.login', 'Logged in');

        return redirect()->intended(route('admin.overview'));
    }

    /**
     * Create the first admin account (and bring the database up to date).
     */
    public function setup(Request $request): RedirectResponse
    {
        $key = config('election.admin_password');
        abort_if(blank($key), 404);

        $validated = $request->validate([
            'setup_key' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        if (! hash_equals($key, $validated['setup_key'])) {
            return back()->withInput($request->except('setup_key', 'password', 'password_confirmation'))
                ->withErrors(['setup_key' => 'Wrong setup key. It is ADMIN_PASSWORD in election-shield/.env.']);
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not update the database: '.$e->getMessage());
        }

        if ($this->hasUsers()) {
            return redirect()->route('admin.login')->with('error', 'An account already exists. Log in instead.');
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => $validated['password'],
            'role' => UserRole::Admin,
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        Audit::record('auth.setup', 'Created the first admin account', $user);

        return redirect()->route('admin.overview')->with('status', "Welcome, {$user->name}. Add accounts for your team under Users.");
    }

    public function logout(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            Audit::record('auth.logout', 'Logged out');
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function hasUsers(): bool
    {
        try {
            return User::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
