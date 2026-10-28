<?php

namespace App\Http\Controllers\Merchant;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Payments\PayInTester;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Merchant self-service: Merchant ID + API credentials, integration checks
 * (credentials, request signature) and a test Pay-In.
 */
class PortalController extends Controller
{
    public function dashboard(Request $request): View
    {
        $merchant = $this->merchant($request);
        $today = $merchant->payments()->where('created_at', '>=', now()->startOfDay());

        return view('merchant.dashboard', [
            'merchant' => $merchant,
            'payments' => $merchant->payments()->latest('id')->limit(15)->get(),
            'stats' => [
                'today' => (clone $today)->count(),
                'paid_today' => (clone $today)->where('status', PaymentStatus::Paid->value)->count(),
                'volume_today' => (float) (clone $today)->where('status', PaymentStatus::Paid->value)->sum('amount'),
            ],
        ]);
    }

    public function revealSecret(Request $request): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $this->confirmPassword($request, $merchant);

        Audit::log('merchant.portal_secret_revealed', $merchant, byAdmin: false);

        return redirect()->to(route('merchant.dashboard').'#credentials')->with('revealed_secret', $merchant->api_secret);
    }

    public function rotateSecret(Request $request): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm the old secret should stop working.']);
        $this->confirmPassword($request, $merchant);

        $secret = $merchant->rotateSecret();
        $merchant->save();

        Audit::log('merchant.portal_secret_rotated', $merchant, byAdmin: false);

        return redirect()->to(route('merchant.dashboard').'#credentials')
            ->with('revealed_secret', $secret)
            ->with('success', 'New API secret created. The old one stopped working; update your website now.');
    }

    public function testForm(Request $request, PayInTester $tester): View
    {
        $merchant = $this->merchant($request);

        return view('merchant.test', [
            'merchant' => $merchant,
            'test' => $tester->present($merchant, session('payin_test')),
        ]);
    }

    public function runTest(Request $request, PayInTester $tester): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $data = $request->validate(['amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0']]);

        $result = $tester->run($merchant, (string) $data['amount'], 'portal');

        Audit::log('merchant.portal_payin_test', $merchant, [], ['amount' => $data['amount'], 'status' => $result['status'], 'payment_id' => $result['payment_id']], byAdmin: false);

        return redirect()->to(route('merchant.test').'#payin-test')->with('payin_test', $result)->withInput(['amount' => $data['amount']]);
    }

    public function verifyForm(Request $request): View
    {
        return view('merchant.verify', ['merchant' => $this->merchant($request)]);
    }

    /**
     * "Are the key and secret in my website the right ones?" Never echoes the secret.
     */
    public function verifyCredentials(Request $request): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:100'],
            'api_secret' => ['required', 'string', 'max:200'],
        ]);

        $result = [
            'key' => hash_equals($merchant->api_key, trim($data['api_key'])),
            'secret' => hash_equals($merchant->api_secret, trim($data['api_secret'])),
        ];

        Audit::log('merchant.portal_credentials_checked', $merchant, [], $result, byAdmin: false);

        return redirect()->to(route('merchant.verify').'#credentials')->with('credential_check', $result);
    }

    /**
     * Recompute the X-Signature for a request the merchant built, exactly as the API does.
     */
    public function verifySignature(Request $request): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $data = $request->validate([
            'sig_timestamp' => ['required', 'digits_between:9,11'],
            'sig_method' => ['required', 'in:GET,POST'],
            'sig_uri' => ['required', 'string', 'max:500', 'starts_with:/'],
            'sig_body' => ['nullable', 'string', 'max:20000'],
            'sig_signature' => ['required', 'string', 'max:128'],
        ]);

        // Textareas submit CRLF line breaks; API clients normally send LF.
        $body = str_replace("\r\n", "\n", (string) ($data['sig_body'] ?? ''));
        $canonical = implode("\n", [$data['sig_timestamp'], $data['sig_method'], $data['sig_uri'], $body]);
        $expected = hash_hmac('sha256', $canonical, $merchant->api_secret);
        $age = time() - (int) $data['sig_timestamp'];

        return redirect()->to(route('merchant.verify').'#signature')
            ->withInput()
            ->with('signature_check', [
                'match' => hash_equals($expected, strtolower(trim($data['sig_signature']))),
                'expected' => $expected,
                'canonical' => $canonical,
                'age' => $age,
                'fresh' => abs($age) <= (int) config('gateway.signature_tolerance'),
            ]);
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user('merchant');
    }

    private function confirmPassword(Request $request, Merchant $merchant): void
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->input('password'), $merchant->password)) {
            throw ValidationException::withMessages(['password' => 'The portal password is incorrect.']);
        }
    }
}
