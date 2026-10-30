<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Payments\PaymentService;
use App\Payments\Qr\QrRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
                'needsPhone' => $payment->isPending() && ! $payment->claimed_phone,
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

    /**
     * The customer tells us the mobile number they will pay FROM, so the
     * payment SMS can be matched to this exact order.
     */
    public function savePhone(Request $request, string $paymentId): RedirectResponse
    {
        $payment = $this->find($paymentId);

        if (! $payment->isPending()) {
            return redirect()->route('checkout.show', $paymentId);
        }

        $data = $request->validate([
            'claimed_phone' => ['required', 'string', 'regex:/^(\+?92|0)?3\d{2}[\s-]?\d{7}$/'],
        ], [
            'claimed_phone.regex' => 'Enter a valid mobile number, e.g. 03001234567.',
        ]);

        $digits = preg_replace('/\D/', '', $data['claimed_phone']);
        $digits = preg_replace('/^92/', '0', $digits);
        if (strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        $payment->forceFill(['claimed_phone' => $digits])->save();
        $this->payments->log($payment, 'payer_number_entered', actor: 'customer', data: ['phone' => $digits], ip: $request->ip());

        return redirect()->route('checkout.show', $paymentId);
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
