<?php

namespace App\Http\Middleware;

use App\Models\SmsDevice;
use App\Payments\Exceptions\GatewayException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SMS forwarder app: "Authorization: Bearer <device key>" (only the key's
 * hash is stored). Disabled devices are rejected.
 */
class AuthenticateSmsDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = SmsDevice::findByKey((string) $request->bearerToken());

        if (! $device || ! $device->is_active) {
            throw new GatewayException('Invalid or disabled device key.', 'unauthenticated', 401);
        }

        $request->attributes->set('sms_device', $device);

        return $next($request);
    }
}
