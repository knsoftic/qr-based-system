<?php

namespace App\Payments;

use App\Http\Middleware\AuthenticateMerchant;
use App\Models\Merchant;
use App\Payments\Qr\QrRenderer;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;

/**
 * "Test Pay-In": sends a real, signed POST /api/v1/payments for a merchant
 * through the application's own HTTP kernel (no network round trip), so the
 * whole API path runs: key + signature check, replay guard, rate limit,
 * validation, idempotency, account routing and the dynamic QR build.
 */
class PayInTester
{
    private const URI = '/api/v1/payments';

    public function __construct(
        private readonly QrRenderer $renderer,
        private readonly PaymentService $payments,
    ) {
    }

    public function run(Merchant $merchant, string $amount, string $source): array
    {
        $body = json_encode([
            'amount' => number_format((float) $amount, 2, '.', ''),
            'order_id' => 'TEST-'.now()->format('ymdHis').'-'.Str::upper(Str::random(4)),
            'description' => 'Pay-In test',
            'metadata' => ['test' => 'true', 'source' => $source],
        ], JSON_UNESCAPED_SLASHES);

        $original = app('request');
        // Same scheme + host as the page, so URLs in the API response (checkout_url) are real ones.
        $request = Request::create($original->getSchemeAndHttpHost().self::URI, 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $original->ip(),
        ], $body);

        $timestamp = (string) time();
        $headers = [
            'X-Api-Key' => $merchant->api_key,
            'X-Timestamp' => $timestamp,
            'X-Signature' => hash_hmac('sha256', AuthenticateMerchant::canonical($timestamp, $request), $merchant->api_secret),
            'Idempotency-Key' => 'test-'.Str::lower(Str::random(24)),
        ];
        $request->headers->add($headers);

        try {
            $response = app(HttpKernel::class)->handle($request);
        } finally {
            // Hand the container back to the page request that triggered the test.
            app()->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }

        $json = json_decode((string) $response->getContent(), true);

        return [
            'source' => $source,
            'status' => $response->getStatusCode(),
            'ok' => $response->getStatusCode() === 201,
            'request' => [
                'method' => 'POST',
                'url' => url(self::URI),
                'headers' => $headers,
                'body' => $body,
            ],
            'response' => $json,
            'payment_id' => $json['data']['payment_id'] ?? null,
            'ran_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Add the test payment (owned by this merchant) and its live QR to a stored result.
     */
    public function present(Merchant $merchant, ?array $result): ?array
    {
        if (! $result) {
            return null;
        }

        $payment = $result['payment_id']
            ? $merchant->payments()->where('payment_id', $result['payment_id'])->first()
            : null;

        if ($payment) {
            $this->payments->expireIfLapsed($payment);
        }

        return $result + [
            'payment' => $payment,
            'qr_svg' => $payment?->isPending() ? $this->renderer->svg($payment->qr_payload) : null,
        ];
    }
}
