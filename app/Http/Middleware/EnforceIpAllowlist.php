<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use App\Payments\Exceptions\GatewayException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-merchant IP allowlisting, enforced after merchant.auth has resolved the
 * merchant. Scope is "payin", "payout" or "any" (balance; the union of both).
 *
 * Strict by design: a side with an empty allowlist is blocked until the admin
 * adds an IP. The client IP is $request->ip(); behind an HTTP reverse proxy
 * (e.g. Cloudflare) configure trustProxies so it is the real client address.
 */
class EnforceIpAllowlist
{
    public function handle(Request $request, Closure $next, string $scope = 'any'): Response
    {
        /** @var Merchant|null $merchant */
        $merchant = $request->attributes->get('merchant');
        $ip = $request->ip();

        if (! $merchant instanceof Merchant || $ip === null || ! $merchant->ipAllowed($ip, $scope)) {
            throw new GatewayException(
                'This IP address is not allowed for this operation. Ask the gateway admin to add it to your '.self::label($scope).'IP allowlist.',
                'ip_not_allowed',
                403,
            );
        }

        return $next($request);
    }

    private static function label(string $scope): string
    {
        return match ($scope) {
            'payin' => 'pay-in ',
            'payout' => 'pay-out ',
            default => '',
        };
    }
}
