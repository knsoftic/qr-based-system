<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Payments\PaymentService;
use App\Payments\Qr\QrRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Customer-facing checkout. Shows the dynamic QR while the payment is
 * pending; backend account details are never rendered.
 */
class CheckoutController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function show(Request $request, string $paymentId, QrRenderer $renderer): Response
    {
        $payment = $this->find($paymentId);

        if (! $payment->events()->where('event', 'checkout_viewed')->exists()) {
            $this->payments->log($payment, 'checkout_viewed', actor: 'customer', ip: $request->ip());
        }

        // An expired / final payment never renders a QR at all.
        $qrSvg = $payment->isPending() ? $renderer->svg($payment->qr_payload) : null;

        return response()
            ->view('checkout.show', [
                'payment' => $payment,
                'qrSvg' => $qrSvg,
                'redirectUrl' => $this->redirectUrl($payment),
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    public function status(string $paymentId): JsonResponse
    {
        $payment = $this->find($paymentId);

        return response()->json([
            'status' => $payment->status->value,
            'remaining_seconds' => $payment->remainingSeconds(),
            'redirect_url' => $this->redirectUrl($payment),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function find(string $paymentId): Payment
    {
        $payment = Payment::query()->with('merchant')->where('payment_id', $paymentId)->firstOrFail();

        return $this->payments->expireIfLapsed($payment);
    }

    private function redirectUrl(Payment $payment): ?string
    {
        if ($payment->isPending() || ! $payment->return_url) {
            return null;
        }

        $query = http_build_query(['payment_id' => $payment->payment_id, 'status' => $payment->status->value]);

        return $payment->return_url.(str_contains($payment->return_url, '?') ? '&' : '?').$query;
    }
}
