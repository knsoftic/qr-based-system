<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Payments\PaymentService;
use App\Payments\Qr\QrRenderer;
use App\Support\CheckoutTheme;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Customer-facing checkout. Reachable both by its long payment id
 * (/checkout/{id}) and by a short, anonymous slug (/s/{slug}); the slug link
 * renders the checkout directly so the address bar stays short and the
 * payment id is never shown. Backend account details are never rendered.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly Settings $settings,
    ) {
    }

    public function show(Request $request, string $key, QrRenderer $renderer): Response
    {
        $payment = $this->find($key);

        if (! $payment->events()->where('event', 'checkout_viewed')->exists()) {
            $this->payments->log($payment, 'checkout_viewed', actor: 'customer', ip: $request->ip());
        }

        // An expired / final payment never renders a QR at all.
        $qrSvg = $payment->isPending() ? $renderer->svg($payment->qr_payload) : null;

        $short = $this->isShort($request);
        $id = $short ? $payment->slug : $payment->payment_id;

        return response()
            ->view('checkout.show', [
                'payment' => $payment,
                'qrSvg' => $qrSvg,
                'needsPhone' => $payment->isPending() && ! $payment->claimed_phone,
                'redirectUrl' => $this->redirectUrl($payment),
                'theme' => new CheckoutTheme($this->settings),
                'statusUrl' => route($short ? 'checkout.short.status' : 'checkout.status', $id),
                'phoneAction' => route($short ? 'checkout.short.phone' : 'checkout.phone', $id),
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    public function status(string $key): JsonResponse
    {
        $payment = $this->find($key);

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
    public function savePhone(Request $request, string $key): RedirectResponse
    {
        $payment = $this->find($key);

        if (! $payment->isPending()) {
            return redirect()->to($this->showUrl($request, $payment));
        }

        $theme = new CheckoutTheme($this->settings);

        $data = $request->validate([
            'claimed_phone' => ['required', 'string', 'regex:/^(\+?92|0)?3\d{2}[\s-]?\d{7}$/'],
        ], [
            'claimed_phone.regex' => $theme->isRtl()
                ? 'درست موبائل نمبر درج کریں، مثلاً 03001234567۔'
                : 'Enter a valid mobile number, e.g. 03001234567.',
        ]);

        $digits = preg_replace('/\D/', '', $data['claimed_phone']);
        $digits = preg_replace('/^92/', '0', $digits);
        if (strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        // The pay-from number must be from an allowed mobile operator code.
        if (! $theme->allows($digits)) {
            $message = $theme->get('invalid_code_msg')
                ?: ($theme->isRtl() ? 'اس موبائل نمبر کا کوڈ قبول نہیں۔' : 'This mobile number prefix is not accepted.');

            return back()->withErrors(['claimed_phone' => $message])->withInput();
        }

        $payment->forceFill(['claimed_phone' => $digits])->save();
        $this->payments->log($payment, 'payer_number_entered', actor: 'customer', data: ['phone' => $digits], ip: $request->ip());

        return redirect()->to($this->showUrl($request, $payment));
    }

    private function find(string $key): Payment
    {
        $payment = Payment::query()->with('merchant')
            ->where('payment_id', $key)
            ->orWhere('slug', $key)
            ->firstOrFail();

        return $this->payments->expireIfLapsed($payment);
    }

    private function isShort(Request $request): bool
    {
        return str_starts_with((string) $request->route()?->getName(), 'checkout.short');
    }

    private function showUrl(Request $request, Payment $payment): string
    {
        return $this->isShort($request)
            ? route('checkout.short', $payment->slug)
            : route('checkout.show', $payment->payment_id);
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
