<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use App\Payments\Exceptions\GatewayException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed merchant requests.
 *
 *   X-Api-Key:   merchant public key
 *   X-Timestamp: unix time (seconds), must be within the tolerance window
 *   X-Signature: hex(HMAC-SHA256(api_secret, timestamp + "\n" + METHOD + "\n" + request URI + "\n" + raw body))
 *
 * A signature is accepted only once (replay protection), except for exact
 * retries of POST requests with an Idempotency-Key.
 */
class AuthenticateMerchant
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = (string) $request->header('X-Api-Key');
        $timestamp = (string) $request->header('X-Timestamp');
        $signature = strtolower((string) $request->header('X-Signature'));

        if ($apiKey === '' || $timestamp === '' || $signature === '') {
            throw $this->fail('Missing X-Api-Key, X-Timestamp or X-Signature header.');
        }

        $merchant = Merchant::query()->where('api_key', $apiKey)->first();

        if (! $merchant || ! $merchant->is_active) {
            throw $this->fail('Invalid API key.');
        }

        $tolerance = (int) config('gateway.signature_tolerance');

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
            throw $this->fail('X-Timestamp is outside the allowed window.', 'timestamp_out_of_range');
        }

        $expected = hash_hmac('sha256', self::canonical($timestamp, $request), $merchant->api_secret);

        if (! hash_equals($expected, $signature)) {
            throw $this->fail('Invalid signature.', 'invalid_signature');
        }

        // An exact retry of a POST carrying an Idempotency-Key is harmless (it
        // returns the original payment), so HTTP clients may safely resend it.
        $idempotentRetry = $request->isMethod('POST') && $request->hasHeader('Idempotency-Key');

        if (! Cache::add('api-signature:'.$signature, true, $tolerance * 2) && ! $idempotentRetry) {
            throw $this->fail('This request was already processed (replayed signature).', 'replayed_request');
        }

        $request->attributes->set('merchant', $merchant);

        return $next($request);
    }

    public static function canonical(string $timestamp, Request $request): string
    {
        return implode("\n", [
            $timestamp,
            strtoupper($request->getMethod()),
            $request->getRequestUri(),
            $request->getContent(),
        ]);
    }

    private function fail(string $message, string $code = 'unauthenticated'): GatewayException
    {
        return new GatewayException($message, $code, 401);
    }
}
