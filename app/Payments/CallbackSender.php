<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POSTs a JSON body to a merchant URL, signed with the merchant secret:
 *   X-Timestamp: unix time
 *   X-Signature: hex(HMAC-SHA256(secret, timestamp + "." + body))
 * Used by real payment callbacks and by the portal's sandbox test callback,
 * so both are byte-for-byte the same shape. Never throws.
 */
class CallbackSender
{
    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * @param  array<string, string>  $extraHeaders
     * @return array{success: bool, code: ?int, body: ?string, error: ?string, duration_ms: int, headers: array<string, string>}
     */
    public function send(string $url, string $body, string $secret, array $extraHeaders = []): array
    {
        $timestamp = (string) time();
        $headers = [
            'User-Agent' => config('app.name').'/1.0',
            'X-Timestamp' => $timestamp,
            'X-Signature' => self::signature($secret, $timestamp, $body),
        ] + $extraHeaders;
        $started = microtime(true);
        $elapsed = fn () => (int) round((microtime(true) - $started) * 1000);

        try {
            $response = Http::timeout(config('gateway.callback_timeout'))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($url);

            return [
                'success' => $response->successful(),
                'code' => $response->status(),
                'body' => mb_substr($response->body(), 0, 2000),
                'error' => null,
                'duration_ms' => $elapsed(),
                'headers' => $headers,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'code' => null,
                'body' => null,
                'error' => mb_substr($e->getMessage(), 0, 250),
                'duration_ms' => $elapsed(),
                'headers' => $headers,
            ];
        }
    }
}
