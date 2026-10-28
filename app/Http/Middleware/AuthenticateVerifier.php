<?php

namespace App\Http\Middleware;

use App\Payments\Exceptions\GatewayException;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects the payment verification API with the verifier key generated in
 * Admin > Settings > Verification (only its SHA-256 hash is stored).
 */
class AuthenticateVerifier
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $hash = $this->settings->get('verification.key_hash');

        if (! $hash) {
            throw new GatewayException('The verification API is disabled. Generate a verifier key in the admin panel.', 'verification_disabled', 503);
        }

        $key = (string) $request->header('X-Verifier-Key');

        if ($key === '' || ! hash_equals($hash, hash('sha256', $key))) {
            throw new GatewayException('Invalid verifier key.', 'unauthenticated', 401);
        }

        return $next($request);
    }
}
