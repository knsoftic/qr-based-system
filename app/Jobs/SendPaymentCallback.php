<?php

namespace App\Jobs;

use App\Models\CallbackDelivery;
use App\Models\Payment;
use App\Payments\CallbackDispatcher;
use App\Support\UrlGuard;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POSTs the payment status to the merchant, signed with the merchant secret:
 *   X-Timestamp: unix time
 *   X-Signature: hex(HMAC-SHA256(secret, timestamp + "." + body))
 */
class SendPaymentCallback
{
    use Dispatchable;

    public function __construct(public readonly int $paymentId)
    {
    }

    public function handle(CallbackDispatcher $dispatcher): void
    {
        $payment = Payment::query()->with('merchant')->find($this->paymentId);

        if (! $payment || ! ($url = $dispatcher->callbackUrl($payment))) {
            return;
        }

        $attempt = $payment->callbackDeliveries()->count() + 1;
        $payload = [
            'event' => 'payment.'.$payment->status->value,
            'data' => $payment->toApiArray(),
            'sent_at' => now()->toIso8601String(),
        ];

        if (! UrlGuard::isAllowed($url)) {
            $this->record($payment, $url, $payload, $attempt, error: 'Blocked: callback URL is not a public http(s) address.');

            return;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $started = microtime(true);

        try {
            $response = Http::timeout(config('gateway.callback_timeout'))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders([
                    'User-Agent' => config('app.name').'/1.0',
                    'X-Timestamp' => $timestamp,
                    'X-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $payment->merchant->api_secret),
                ])
                ->withBody($body, 'application/json')
                ->post($url);

            $success = $response->successful();

            $this->record($payment, $url, $payload, $attempt,
                code: $response->status(),
                body: mb_substr($response->body(), 0, 2000),
                success: $success,
                started: $started,
            );

            if ($success) {
                $payment->forceFill(['callback_sent_at' => now()])->save();
            }
        } catch (Throwable $e) {
            $this->record($payment, $url, $payload, $attempt, error: mb_substr($e->getMessage(), 0, 250), started: $started);
        }
    }

    private function record(Payment $payment, string $url, array $payload, int $attempt, ?int $code = null, ?string $body = null, ?string $error = null, bool $success = false, ?float $started = null): void
    {
        CallbackDelivery::query()->create([
            'payment_id' => $payment->id,
            'url' => $url,
            'payload' => $payload,
            'attempt' => $attempt,
            'response_code' => $code,
            'response_body' => $body,
            'error' => $error,
            'success' => $success,
            'duration_ms' => $started ? (int) round((microtime(true) - $started) * 1000) : null,
        ]);
    }
}
