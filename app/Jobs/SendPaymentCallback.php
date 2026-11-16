<?php

namespace App\Jobs;

use App\Models\CallbackDelivery;
use App\Models\Payment;
use App\Payments\CallbackDispatcher;
use App\Payments\CallbackSender;
use App\Support\UrlGuard;
use App\Telegram\TelegramNotifier;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * POSTs the payment status to the merchant, signed with the merchant secret
 * (see CallbackSender), and records each delivery attempt.
 */
class SendPaymentCallback
{
    use Dispatchable;

    public function __construct(public readonly int $paymentId) {}

    public function handle(CallbackDispatcher $dispatcher, CallbackSender $sender): void
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

        $result = $sender->send($url, json_encode($payload, JSON_UNESCAPED_SLASHES), $payment->merchant->api_secret);

        $this->record($payment, $url, $payload, $attempt,
            code: $result['code'],
            body: $result['body'],
            error: $result['error'],
            success: $result['success'],
            durationMs: $result['duration_ms'],
        );

        if ($result['success']) {
            $payment->forceFill(['callback_sent_at' => now()])->save();
        }
    }

    private function record(Payment $payment, string $url, array $payload, int $attempt, ?int $code = null, ?string $body = null, ?string $error = null, bool $success = false, ?int $durationMs = null): void
    {
        $delivery = CallbackDelivery::query()->create([
            'payment_id' => $payment->id,
            'url' => $url,
            'payload' => $payload,
            'attempt' => $attempt,
            'response_code' => $code,
            'response_body' => $body,
            'error' => $error,
            'success' => $success,
            'duration_ms' => $durationMs,
        ]);

        if (! $success) {
            try {
                app(TelegramNotifier::class)->callbackFailed($payment, $delivery);
            } catch (Throwable) {
                // Best-effort Telegram notification.
            }
        }
    }
}
