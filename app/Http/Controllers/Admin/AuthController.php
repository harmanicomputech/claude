<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function show(): View
    {
        abort_if(blank(config('election.admin_password')), 404);

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $password = config('election.admin_password');
        abort_if(blank($password), 404);

        $request->validate(['password' => ['required', 'string']]);

        if (! hash_equals($password, $request->string('password')->toString())) {
            return back()->withErrors(['password' => 'Wrong password.']);
        }

        $request->session()->regenerate();
        $request->session()->put('admin.authenticated', true);

        return redirect()->intended(route('admin.overview'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
