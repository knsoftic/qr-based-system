<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('merchant.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ]);

        $guard = Auth::guard('merchant');
        $credentials = [
            'username' => Str::lower($data['username']),
            'password' => $data['password'],
            'is_active' => true,
            'portal_enabled' => true,
        ];

        if (! $guard->attempt($credentials)) {
            Audit::log('merchant.portal_login_failed', null, [], ['username' => $credentials['username']], byAdmin: false);

            throw ValidationException::withMessages(['username' => 'These credentials do not match an active merchant login.']);
        }

        $request->session()->regenerate();

        $merchant = $guard->user();
        $request->session()->put('merchant_session_version', $merchant->session_version);
        $merchant->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        Audit::log('merchant.portal_login', $merchant, byAdmin: false);

        return redirect()->route('merchant.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::log('merchant.portal_logout', $request->user('merchant'), byAdmin: false);

        // Only the merchant guard: an admin signed in on the same browser stays signed in.
        Auth::guard('merchant')->logout();
        $request->session()->forget('merchant_session_version');
        $request->session()->regenerateToken();

        return redirect()->route('merchant.login');
    }
}
