<?php

namespace App\Http\Controllers\Merchant;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendPaymentCallback;
use App\Models\Merchant;
use App\Models\Payout;
use App\Payments\CallbackDispatcher;
use App\Payments\CallbackSender;
use App\Payments\Exceptions\GatewayException;
use App\Payments\FeeCalculator;
use App\Payments\PayInTester;
use App\Payments\PaymentService;
use App\Payments\PayoutService;
use App\Support\Audit;
use App\Support\Settings;
use App\Support\UrlGuard;
use App\Telegram\LinkService;
use App\Telegram\TelegramClient;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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
            'chart' => $this->chartData($merchant, $period),
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
                    fputcsv($out, array_map([self::class, 'csvSafe'], [
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

    /**
     * Dashboard chart series for a period, in Pakistan time: hourly for
     * today, daily for 7 / 30 days, monthly for all time (at most 24 months).
     * Each bucket: orders, paid, paid volume and success rate.
     *
     * @return array{unit: string, buckets: list<array{key: string, short: string, long: string, orders: int, paid: int, volume: float, rate: ?float, future: bool}>, ticks: list<float>, top: float, orders: int, paid: int, volume: float}
     */
    private function chartData(Merchant $merchant, string $period): array
    {
        $tz = 'Asia/Karachi';
        $now = now($tz);

        if ($period === 'all') {
            $first = $merchant->payments()->min('created_at');
            $start = $first ? Carbon::parse($first, config('app.timezone'))->setTimezone($tz)->startOfMonth() : $now->copy()->startOfMonth();
            $start = $start->max($now->copy()->startOfMonth()->subMonthsNoOverflow(23));
            [$unit, $step, $fmt, $short, $long] = ['month', 'addMonthNoOverflow', 'Y-m', "M 'y", 'F Y'];
        } else {
            [$unit, $start] = match ($period) {
                '7d' => ['day', $now->copy()->subDays(6)->startOfDay()],
                '30d' => ['day', $now->copy()->subDays(29)->startOfDay()],
                default => ['hour', $now->copy()->startOfDay()],
            };
            [$step, $fmt, $short, $long] = $unit === 'hour'
                ? ['addHour', 'Y-m-d H', 'g A', 'd M, g A']
                : ['addDay', 'Y-m-d', 'j M', 'D, j M'];
        }

        // Today always spans the whole day (hours still to come stay empty),
        // so the axis does not shrink to a single bar just after midnight.
        $end = $unit === 'hour' ? $now->copy()->endOfDay() : $now;
        $buckets = [];
        for ($t = $start->copy(); $t->lte($end); $t = $t->{$step}()) {
            $buckets[$t->format($fmt)] = [
                'key' => $t->format($fmt), 'short' => $t->format($short), 'long' => $t->format($long),
                'orders' => 0, 'paid' => 0, 'volume' => 0.0, 'rate' => null, 'future' => $t->gt($now),
            ];
        }

        $since = $start->copy()->setTimezone(config('app.timezone'));
        foreach ($merchant->payments()->where('created_at', '>=', $since)->select(['id', 'created_at', 'status', 'amount'])->cursor() as $p) {
            $key = $p->created_at->timezone($tz)->format($fmt);
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['orders']++;
            if ($p->status->value === 'paid') {
                $buckets[$key]['paid']++;
                $buckets[$key]['volume'] += (float) $p->amount;
            }
        }

        foreach ($buckets as &$b) {
            $b['volume'] = round($b['volume'], 2);
            $b['rate'] = $b['orders'] ? round($b['paid'] / $b['orders'] * 100, 1) : null;
        }
        unset($b);
        $buckets = array_values($buckets);

        // Clean y-axis ticks for the volume chart (0 … top in ~3 steps).
        $max = $buckets ? max(array_column($buckets, 'volume')) : 0.0;
        $tick = $this->niceStep($max / 3);
        $top = $max > 0 ? $tick * ceil($max / $tick) : $tick;

        return [
            'unit' => $unit,
            'buckets' => $buckets,
            'ticks' => range(0, $top, $tick),
            'top' => (float) $top,
            'orders' => array_sum(array_column($buckets, 'orders')),
            'paid' => array_sum(array_column($buckets, 'paid')),
            'volume' => round(array_sum(array_column($buckets, 'volume')), 2),
        ];
    }

    /** 1 / 2 / 2.5 / 5 × 10ⁿ step at or above $x. */
    private function niceStep(float $x): float
    {
        if ($x <= 0) {
            return 1.0;
        }
        $pow = 10 ** floor(log10($x));
        $f = $x / $pow;

        return (float) ($pow * match (true) {
            $f <= 1 => 1,
            $f <= 2 => 2,
            $f <= 2.5 => 2.5,
            $f <= 5 => 5,
            default => 10,
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

    /**
     * Sandbox: send a signed callback to the merchant's server exactly like a
     * real one ("sandbox": true, header X-Sandbox: true) for a made-up payment.
     * Nothing is created and no status or balance changes, so the merchant can
     * test its callback handler without moving money.
     */
    public function testCallback(Request $request, CallbackSender $sender, FeeCalculator $fees): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $max = (float) app(Settings::class)->get('payin.max_amount');
        $back = redirect()->to(route('merchant.verify').'#callback')->withInput();

        $data = $request->validate([
            'cb_event' => ['required', Rule::in(['paid', 'expired', 'failed'])],
            'cb_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.$max],
            'cb_order_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-.:#\/]+$/'],
            'cb_url' => ['nullable', 'url:http,https', 'max:255'],
        ]);

        $url = $data['cb_url'] ?? $merchant->callback_url;
        if (! $url) {
            return $back->with('error', 'Enter a callback URL (or ask the admin to set your default one).');
        }
        if (! UrlGuard::isAllowed($url)) {
            return $back->with('error', 'The callback URL must be a public http(s) address.');
        }

        $event = $data['cb_event'];
        $amount = number_format((float) $data['cb_amount'], 2, '.', '');
        $fee = $fees->payIn($merchant, $amount);
        $now = now();

        $payload = [
            'event' => 'payment.'.$event,
            'data' => [
                'payment_id' => 'pay_sandbox_'.Str::lower(Str::random(16)),
                'transaction_id' => 'TXNSBX'.$now->format('ymdHis').random_int(1000, 9999),
                'order_id' => $data['cb_order_id'] ?? 'SANDBOX-'.Str::upper(Str::random(6)),
                'amount' => $amount,
                'currency' => 'PKR',
                'fee' => $fee['fee'],
                'net_amount' => $fee['net'],
                'status' => $event,
                'checkout_url' => null,
                'short_url' => null,
                'expires_at' => $now->copy()->addMinutes($event === 'expired' ? 0 : 5)->toIso8601String(),
                'paid_at' => $event === 'paid' ? $now->toIso8601String() : null,
                'created_at' => $now->copy()->subMinute()->toIso8601String(),
            ],
            'sent_at' => $now->toIso8601String(),
            'sandbox' => true,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $result = $sender->send($url, $body, $merchant->api_secret, ['X-Sandbox' => 'true']);

        Audit::log('merchant.sandbox_callback_sent', $merchant, [], ['event' => $event, 'url' => $url, 'code' => $result['code']], byAdmin: false);

        return $back->with('callback_test', ['url' => $url, 'request_body' => $body] + $result);
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

    /**
     * One payment: amounts, verification, a customer-facing timeline and the
     * callback deliveries. Gateway internals (account, IPs, SMS body, admin
     * ids) are never shown to the merchant.
     */
    public function showPayment(Request $request, string $paymentId, PaymentService $payments): View
    {
        $merchant = $this->merchant($request);
        $payment = $merchant->payments()->where('payment_id', $paymentId)->firstOrFail();
        $payments->expireIfLapsed($payment);
        $payment->load(['events', 'callbackDeliveries']);

        return view('merchant.payment', [
            'merchant' => $merchant,
            'payment' => $payment,
            'timeline' => $payment->events->map(fn ($e) => $this->timelineEntry($e))->all(),
            'verifiedVia' => $this->actorLabel($payment->verified_by),
            'canResend' => ! $payment->isPending() && (bool) app(CallbackDispatcher::class)->callbackUrl($payment),
        ]);
    }

    public function resendCallback(Request $request, string $paymentId, CallbackDispatcher $dispatcher): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $payment = $merchant->payments()->where('payment_id', $paymentId)->firstOrFail();
        $back = redirect()->route('merchant.payments.show', $payment->payment_id);

        if ($payment->isPending()) {
            return $back->with('error', 'This payment is still pending; the callback is sent when it completes.');
        }
        if (! $dispatcher->callbackUrl($payment)) {
            return $back->with('error', 'No callback URL is set for this payment.');
        }

        SendPaymentCallback::dispatchSync($payment->id);
        Audit::log('merchant.callback_resent', $payment, [], [], byAdmin: false);

        $last = $payment->callbackDeliveries()->latest('id')->first();

        return $back->with($last?->success ? 'success' : 'error', $last?->success
            ? "Callback delivered (HTTP {$last->response_code})."
            : 'Callback failed: '.($last?->error ?: 'HTTP '.$last?->response_code));
    }

    public function account(Request $request): View
    {
        return view('merchant.account', ['merchant' => $this->merchant($request)]);
    }

    /**
     * The merchant changes its own portal password. Other open sessions are
     * signed out; this one stays signed in.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(10)->letters()->numbers()],
        ]);

        if (! Hash::check($data['current_password'], $merchant->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        $merchant->password = $data['password'];
        $merchant->revokePortalSessions();
        $merchant->save();
        $request->session()->put('merchant_session_version', $merchant->session_version);

        Audit::log('merchant.portal_password_changed', $merchant, byAdmin: false);

        return redirect()->route('merchant.account')->with('success', 'Password changed. Other devices were signed out.');
    }

    /**
     * Monthly balance statement: opening balance, one row per Pakistan day
     * (payments received, withdrawals held, withdrawals returned) and the
     * closing balance. Uses the same formula as the available balance.
     */
    public function statement(Request $request): View
    {
        $merchant = $this->merchant($request);
        $months = $this->statementMonths();
        $month = $this->pickMonth($request, $months);
        [$start, $end] = $this->monthRange($month);

        $opening = $this->balanceBefore($merchant, $start);
        $days = [];
        $add = function (string $day, string $key, float $amount, int $count = 0) use (&$days) {
            $days[$day] ??= ['payments' => 0, 'received' => 0.0, 'withdrawn' => 0.0, 'returned' => 0.0];
            $days[$day][$key] += $amount;
            $days[$day]['payments'] += $count;
        };

        foreach ($merchant->payments()->where('status', 'paid')->whereBetween('paid_at', [$start, $end])->select(['id', 'paid_at', 'net_amount'])->cursor() as $p) {
            $add($p->paid_at->timezone('Asia/Karachi')->format('Y-m-d'), 'received', (float) $p->net_amount, 1);
        }
        foreach ($merchant->payouts()->whereBetween('created_at', [$start, $end])->get(['created_at', 'amount']) as $po) {
            $add($po->created_at->timezone('Asia/Karachi')->format('Y-m-d'), 'withdrawn', (float) $po->amount);
        }
        foreach ($merchant->payouts()->where('status', 'rejected')->whereBetween('processed_at', [$start, $end])->get(['processed_at', 'amount']) as $po) {
            $add($po->processed_at->timezone('Asia/Karachi')->format('Y-m-d'), 'returned', (float) $po->amount);
        }
        ksort($days);

        $balance = $opening;
        $rows = [];
        foreach ($days as $day => $d) {
            $balance = round($balance + $d['received'] - $d['withdrawn'] + $d['returned'], 2);
            $rows[] = $d + ['date' => $day, 'closing' => $balance];
        }

        return view('merchant.statement', [
            'merchant' => $merchant,
            'months' => $months,
            'month' => $month,
            'opening' => $opening,
            'closing' => $balance,
            'rows' => $rows,
            'totals' => [
                'payments' => array_sum(array_column($rows, 'payments')),
                'received' => array_sum(array_column($rows, 'received')),
                'withdrawn' => array_sum(array_column($rows, 'withdrawn')),
                'returned' => array_sum(array_column($rows, 'returned')),
            ],
        ]);
    }

    /** Line-by-line statement for a month with a running balance (CSV). */
    public function exportStatement(Request $request): StreamedResponse
    {
        $merchant = $this->merchant($request);
        $month = $this->pickMonth($request, $this->statementMonths());
        [$start, $end] = $this->monthRange($month);
        $opening = $this->balanceBefore($merchant, $start);

        // Payout lines are few: build them up front, then merge them in time
        // order with the (possibly many) payment lines streamed from the DB.
        $payoutLines = collect();
        foreach ($merchant->payouts()->whereBetween('created_at', [$start, $end])->get() as $po) {
            $payoutLines->push(['at' => $po->created_at, 'type' => 'Withdrawal requested', 'ref' => $po->payout_ref, 'amount' => -(float) $po->amount]);
        }
        foreach ($merchant->payouts()->where('status', 'rejected')->whereBetween('processed_at', [$start, $end])->get() as $po) {
            $payoutLines->push(['at' => $po->processed_at, 'type' => 'Withdrawal returned', 'ref' => $po->payout_ref, 'amount' => (float) $po->amount]);
        }
        $payoutLines = $payoutLines->sortBy(fn ($l) => $l['at']->getTimestamp())->values();

        $payments = $merchant->payments()->where('status', 'paid')->whereBetween('paid_at', [$start, $end])
            ->orderBy('paid_at')->orderBy('id')->select(['id', 'payment_id', 'order_id', 'paid_at', 'net_amount']);

        Audit::log('merchant.statement_exported', $merchant, [], ['month' => $month], byAdmin: false);

        return response()->streamDownload(function () use ($payments, $payoutLines, $opening) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date (PKT)', 'Type', 'Reference', 'Order', 'Credit', 'Debit', 'Balance']);
            fputcsv($out, ['', 'Opening balance', '', '', '', '', number_format($opening, 2, '.', '')]);
            $balance = $opening;
            $i = 0;
            $write = function (array $l) use ($out, &$balance) {
                $balance = round($balance + $l['amount'], 2);
                // Only the text columns can carry user input; numbers stay as-is
                // so a negative balance is not mangled by the formula guard.
                fputcsv($out, [
                    $l['at']->timezone('Asia/Karachi')->format('Y-m-d h:i:s A'), $l['type'],
                    self::csvSafe($l['ref']), self::csvSafe($l['order'] ?? ''),
                    $l['amount'] > 0 ? number_format($l['amount'], 2, '.', '') : '',
                    $l['amount'] < 0 ? number_format(-$l['amount'], 2, '.', '') : '',
                    number_format($balance, 2, '.', ''),
                ]);
            };

            foreach ($payments->cursor() as $p) {
                while ($i < $payoutLines->count() && $payoutLines[$i]['at']->lte($p->paid_at)) {
                    $write($payoutLines[$i++]);
                }
                $write(['at' => $p->paid_at, 'type' => 'Payment received', 'ref' => $p->payment_id, 'order' => $p->order_id, 'amount' => (float) $p->net_amount]);
            }
            while ($i < $payoutLines->count()) {
                $write($payoutLines[$i++]);
            }

            fputcsv($out, ['', 'Closing balance', '', '', '', '', number_format($balance, 2, '.', '')]);
            fclose($out);
        }, 'statement-'.$month.'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Available balance at a moment: paid net − withdrawals requested + withdrawals returned. */
    private function balanceBefore(Merchant $merchant, Carbon $at): float
    {
        $in = (float) $merchant->payments()->where('status', 'paid')->where('paid_at', '<', $at)->sum('net_amount');
        $out = (float) $merchant->payouts()->where('created_at', '<', $at)->sum('amount');
        $back = (float) $merchant->payouts()->where('status', 'rejected')->where('processed_at', '<', $at)->sum('amount');

        return round($in - $out + $back, 2);
    }

    /** The last 12 Pakistan calendar months, newest first: "2026-11" => "November 2026". */
    private function statementMonths(): array
    {
        $months = [];
        $m = now('Asia/Karachi')->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $months[$m->format('Y-m')] = $m->format('F Y');
            $m = $m->subMonthNoOverflow();
        }

        return $months;
    }

    private function pickMonth(Request $request, array $months): string
    {
        $month = (string) $request->query('month');

        return array_key_exists($month, $months) ? $month : (string) array_key_first($months);
    }

    /** @return array{0: Carbon, 1: Carbon} month start/end in the app timezone */
    private function monthRange(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01', 'Asia/Karachi')->startOfDay();
        $tz = config('app.timezone');

        return [$start->copy()->setTimezone($tz), $start->copy()->endOfMonth()->setTimezone($tz)];
    }

    /** @return array{label: string, note: ?string, from: ?string, to: ?string, at: \DateTimeInterface} */
    private function timelineEntry($event): array
    {
        $note = null;
        $label = match ($event->event) {
            'created' => 'Payment created',
            'checkout_viewed' => 'Customer opened the checkout',
            'payer_number_entered' => 'Customer entered the pay-from number',
            'status_changed' => match ($event->to_status) {
                'paid' => 'Payment received',
                'expired' => 'QR expired',
                'failed' => 'Marked as failed',
                'cancelled' => 'Cancelled',
                default => 'Status changed',
            },
            default => ucfirst(str_replace('_', ' ', $event->event)),
        };

        if ($event->event === 'payer_number_entered') {
            $note = $event->data['phone'] ?? null;
        } elseif ($event->event === 'status_changed') {
            $note = $this->actorLabel($event->actor).(! empty($event->data['late']) ? ' · late payment' : '');
        }

        return ['label' => $label, 'note' => $note, 'from' => $event->from_status, 'to' => $event->to_status, 'at' => $event->created_at];
    }

    /** Who acted, in merchant-friendly words (never an internal admin id). */
    private function actorLabel(?string $actor): string
    {
        return match (true) {
            $actor === null => '—',
            $actor === 'sms' => 'Automatic (SMS)',
            $actor === 'api' => 'Verifier API',
            $actor === 'system' => 'Automatic',
            str_starts_with($actor, 'admin') => 'Gateway admin',
            default => ucfirst($actor),
        };
    }

    /** Prevent spreadsheet formula injection in exported cells. */
    public static function csvSafe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
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
