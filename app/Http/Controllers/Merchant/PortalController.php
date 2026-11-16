<?php

namespace App\Http\Controllers\Merchant;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Payout;
use App\Payments\Exceptions\GatewayException;
use App\Payments\PayInTester;
use App\Payments\PaymentService;
use App\Payments\PayoutService;
use App\Support\Audit;
use App\Support\Settings;
use App\Telegram\LinkService;
use App\Telegram\TelegramClient;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Merchant self-service: Merchant ID + API credentials, integration checks
 * (credentials, request signature) and a test Pay-In.
 */
class PortalController extends Controller
{
    /** Dashboard periods: key => label. "Today" is the Pakistan calendar day. */
    public const PERIODS = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'all' => 'All time',
    ];

    public function dashboard(Request $request, PaymentService $payments): View
    {
        $payments->expireLapsed();
        $merchant = $this->merchant($request);

        $period = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : 'today';
        $since = $this->periodStart($period);

        // One grouped query for the period's counts and volumes.
        $agg = $merchant->payments()
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw('COUNT(*) total')
            ->selectRaw("SUM(status='paid') paid")
            ->selectRaw("SUM(status IN ('failed','cancelled')) failed")
            ->selectRaw("SUM(status='expired') expired")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) volume")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN fee_amount ELSE 0 END) fees")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN net_amount ELSE 0 END) net")
            ->first();

        $payout = (float) $merchant->payouts()->where('status', 'paid')
            ->when($since, fn ($q) => $q->where('processed_at', '>=', $since))
            ->sum('amount');

        $total = (int) $agg->total;
        $paid = (int) $agg->paid;

        return view('merchant.dashboard', [
            'merchant' => $merchant,
            'payments' => $merchant->payments()->latest('id')->limit(10)->get(),
            'paymentCount' => $merchant->payments()->count(),
            'balance' => $merchant->balance(),
            'period' => $period,
            'periodLabel' => self::PERIODS[$period],
            'stats' => [
                'total' => $total,
                'pending' => $merchant->payments()->where('status', 'pending')->count(), // right now
                'paid' => $paid,
                'failed' => (int) $agg->failed,
                'expired' => (int) $agg->expired,
                'volume' => (float) $agg->volume,
                'fees' => (float) $agg->fees,
                'net' => (float) $agg->net,
                'payout' => $payout,
                'success_rate' => $total ? round($paid / $total * 100, 1) : 0.0,
            ],
        ]);
    }

    /**
     * Every payment of this merchant, searchable and filterable, with totals
     * for the filtered set.
     */
    public function payments(Request $request, PaymentService $payments): View
    {
        $payments->expireLapsed();
        $merchant = $this->merchant($request);
        $query = $this->filteredPayments($request, $merchant);

        $sum = (clone $query)
            ->selectRaw('COUNT(*) total')
            ->selectRaw("SUM(status='paid') paid")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) volume")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN fee_amount ELSE 0 END) fees")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN net_amount ELSE 0 END) net")
            ->first();

        return view('merchant.payments', [
            'merchant' => $merchant,
            'payments' => (clone $query)->latest('id')->paginate(25)->withQueryString(),
            'statuses' => PaymentStatus::cases(),
            'summary' => [
                'total' => (int) $sum->total,
                'paid' => (int) $sum->paid,
                'volume' => (float) $sum->volume,
                'fees' => (float) $sum->fees,
                'net' => (float) $sum->net,
            ],
        ]);
    }

    public function exportPayments(Request $request): StreamedResponse
    {
        $merchant = $this->merchant($request);
        $query = $this->filteredPayments($request, $merchant)->latest('id');

        Audit::log('merchant.payments_exported', $merchant, [], $request->only(['q', 'status', 'from', 'to']), byAdmin: false);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Transaction ID', 'Payment ID', 'Order ID', 'Amount', 'Fee', 'Net', 'Currency', 'Status', 'Payer number', 'Created (PKT)', 'Paid (PKT)']);

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $p) {
                    fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, [
                        $p->transaction_id, $p->payment_id, $p->order_id,
                        $p->amount, $p->fee_amount, $p->net_amount, $p->currency, $p->status->value,
                        $p->payer_phone ?: $p->claimed_phone,
                        $p->created_at?->timezone('Asia/Karachi')->format('Y-m-d h:i:s A'),
                        $p->paid_at?->timezone('Asia/Karachi')->format('Y-m-d h:i:s A'),
                    ]));
                }
            });

            fclose($out);
        }, 'payments-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * The merchant's payments narrowed by search / status / date (dates are
     * Pakistan calendar days). Unordered, so it can also be aggregated.
     */
    private function filteredPayments(Request $request, Merchant $merchant): HasMany
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $tz = config('app.timezone');

        return $merchant->payments()
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('from'), fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from, 'Asia/Karachi')->startOfDay()->setTimezone($tz)))
            ->when($request->input('to'), fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to, 'Asia/Karachi')->endOfDay()->setTimezone($tz)))
            ->when(trim((string) $request->input('q')), function ($q, $term) {
                $digits = preg_replace('/\D/', '', $term);
                $q->where(function ($inner) use ($term, $digits) {
                    $inner->where('payment_id', $term)
                        ->orWhere('transaction_id', $term)
                        ->orWhere('order_id', 'like', '%'.$term.'%');
                    if (strlen($digits) >= 4) {
                        $inner->orWhere('claimed_phone', 'like', '%'.$digits.'%')
                            ->orWhere('payer_phone', 'like', '%'.$digits.'%');
                    }
                });
            });
    }

    /** Start of a dashboard period in the app timezone; null = all time. */
    private function periodStart(string $period): ?Carbon
    {
        $start = match ($period) {
            '7d' => now('Asia/Karachi')->subDays(6)->startOfDay(),
            '30d' => now('Asia/Karachi')->subDays(29)->startOfDay(),
            'all' => null,
            default => now('Asia/Karachi')->startOfDay(),
        };

        return $start?->setTimezone(config('app.timezone'));
    }

    public function payouts(Request $request): View
    {
        $merchant = $this->merchant($request);

        return view('merchant.payouts', [
            'merchant' => $merchant,
            'balance' => $merchant->balance(),
            'payouts' => $merchant->payouts()->latest('id')->paginate(15),
            'methods' => Payout::METHODS,
            'enabled' => (bool) app(Settings::class)->get('payout.enabled'),
            'min' => (float) app(Settings::class)->get('payout.min_amount'),
            'max' => (float) app(Settings::class)->get('payout.max_amount'),
        ]);
    }

    public function requestPayout(Request $request, PayoutService $payouts): RedirectResponse
    {
        $merchant = $this->merchant($request);

        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(Payout::METHODS))],
            'account_number' => ['required', 'string', 'regex:/^0?3\d{2}[\s-]?\d{7}$/'],
            'account_name' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['account_number.regex' => 'Enter a valid mobile number, e.g. 03001234567.']);

        $digits = preg_replace('/\D/', '', $data['account_number']);
        $data['account_number'] = strlen($digits) === 10 ? '0'.$digits : $digits;

        try {
            $payout = $payouts->request($merchant, $data);
        } catch (GatewayException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        Audit::log('merchant.payout_requested', null, [], [
            'merchant' => $merchant->merchant_code, 'payout' => $payout->payout_ref,
            'amount' => $payout->amount, 'method' => $payout->method,
        ], byAdmin: false);

        return redirect()->route('merchant.payouts')
            ->with('success', "Withdrawal request {$payout->payout_ref} for PKR ".number_format((float) $payout->amount, 2).' submitted. The amount is on hold until an admin pays it.');
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

    /**
     * Start the "Connect Telegram" handshake: create a single-use token and
     * show the deep link the merchant opens in Telegram.
     */
    public function connectTelegram(Request $request, LinkService $links, TelegramClient $client): RedirectResponse
    {
        $merchant = $this->merchant($request);

        if (! $client->botUsername()) {
            return redirect()->to(route('merchant.dashboard').'#telegram')
                ->with('error', 'Telegram is not set up yet. Please ask the gateway admin.');
        }

        $token = $links->createToken($merchant);
        Audit::log('merchant.telegram_connect_started', $merchant, byAdmin: false);

        return redirect()->to(route('merchant.dashboard').'#telegram')
            ->with('telegram_link', $links->deepLink($token));
    }

    public function disconnectTelegram(Request $request, LinkService $links): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $links->unbind($merchant);
        Audit::log('merchant.telegram_disconnected', $merchant, byAdmin: false);

        return redirect()->to(route('merchant.dashboard').'#telegram')
            ->with('success', 'Telegram disconnected. You will no longer receive notifications there.');
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
