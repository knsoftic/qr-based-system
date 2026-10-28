<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant portal guard: signed in on the "merchant" guard, merchant and its
 * portal login still enabled, and the session not revoked by the admin
 * (password reset / disable bumps session_version).
 */
class EnsureMerchantPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('merchant');

        if (! $guard->check()) {
            return redirect()->route('merchant.login');
        }

        $merchant = $guard->user();

        if (! $merchant->is_active || ! $merchant->portal_enabled
            || $request->session()->get('merchant_session_version') !== $merchant->session_version) {
            $guard->logout();
            $request->session()->forget('merchant_session_version');
            $request->session()->regenerateToken();

            return redirect()->route('merchant.login')
                ->withErrors(['username' => 'Your session has ended. Please sign in again.']);
        }

        return $next($request);
    }
}
