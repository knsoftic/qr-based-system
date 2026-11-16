<?php

namespace App\Http\Controllers;

use App\Models\PaymentLink;
use App\Payments\Exceptions\GatewayException;
use App\Payments\PaymentService;
use App\Support\CheckoutTheme;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Public side of a payment link: /p/{code} shows the amount (or asks for one)
 * and "Pay" creates a fresh payment, then sends the customer to its checkout.
 * Opening the link never creates a payment (link previews do GET requests).
 */
class PaymentLinkController extends Controller
{
    public function __construct(private readonly Settings $settings) {}

    public function show(string $code): View
    {
        $link = $this->find($code);

        return view('paylink.show', [
            'link' => $link,
            'theme' => new CheckoutTheme($this->settings),
            'min' => (float) $this->settings->get('payin.min_amount'),
            'max' => (float) $this->settings->get('payin.max_amount'),
        ]);
    }

    public function pay(Request $request, string $code, PaymentService $payments): RedirectResponse
    {
        $link = $this->find($code);

        if (! $link->isUsable()) {
            return redirect()->route('paylink.show', $link->code)->with('error', 'This payment link is no longer active.');
        }

        $min = (float) $this->settings->get('payin.min_amount');
        $max = (float) $this->settings->get('payin.max_amount');

        $data = $request->validate([
            'amount' => $link->isOpenAmount() ? ['required', 'numeric', 'decimal:0,2', 'min:'.$min, 'max:'.$max] : ['prohibited'],
            'name' => ['nullable', 'string', 'max:100'],
        ], [
            'amount.min' => "Enter at least PKR {$min}.",
            'amount.max' => "Enter at most PKR {$max}.",
        ]);

        $amount = $link->isOpenAmount() ? (float) $data['amount'] : (float) $link->amount;
        $name = trim(strip_tags((string) ($data['name'] ?? ''))) ?: null;

        try {
            [$payment] = $payments->create($link->merchant, [
                'amount' => number_format($amount, 2, '.', ''),
                'order_id' => 'PL-'.Str::upper(Str::random(6)).'-'.now()->format('ymdHis'),
                'description' => Str::limit($link->title, 255, ''),
                'customer' => ['name' => $name],
                'metadata' => ['source' => 'payment_link', 'payment_link' => $link->code],
            ], 'pl-'.Str::lower(Str::random(24)), $request->ip());
        } catch (GatewayException $e) {
            return redirect()->route('paylink.show', $link->code)->withInput()->with('error', $e->getMessage());
        }

        $payment->forceFill(['payment_link_id' => $link->id])->save();

        return redirect()->to($payment->shortUrl() ?: $payment->checkoutUrl());
    }

    private function find(string $code): PaymentLink
    {
        return PaymentLink::query()->with('merchant')->where('code', strtolower($code))->firstOrFail();
    }
}
