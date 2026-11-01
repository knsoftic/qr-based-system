@extends('layouts.merchant')

@section('title', 'Dashboard')

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $merchant->name }}</h1>
            <p>Merchant ID <span class="mono">{{ $merchant->merchant_code }}</span> · {{ now()->timezone('Asia/Karachi')->format('l, d M Y') }}</p>
        </div>
        <div class="actions">
            <a href="{{ route('merchant.test') }}" class="btn btn--primary"><x-icon name="zap" :size="16" /> Test Pay-In</a>
            <a href="{{ route('merchant.verify') }}" class="btn"><x-icon name="check" :size="16" /> Verify integration</a>
        </div>
    </div>

    <div class="balance-hero">
        <div>
            <div class="balance-label">Available balance</div>
            <div class="balance-value">PKR {{ number_format($balance['available'], 2) }}</div>
            <div class="balance-note">
                On hold (withdrawing): PKR {{ number_format($balance['frozen'], 2) }} ·
                Paid out: PKR {{ number_format($balance['paid_out'], 2) }}.
                <a href="{{ route('merchant.payouts') }}" style="color:#fff;text-decoration:underline">Withdraw →</a>
            </div>
        </div>
        <div class="balance-side">
            <div><span class="balance-side-label">Pay-In today</span><span class="balance-side-value">PKR {{ number_format($stats['volume'], 2) }}</span></div>
            <div><span class="balance-side-label">Pay-Out today</span><span class="balance-side-value">PKR {{ number_format($stats['payout_today'], 2) }}</span></div>
            <div><span class="balance-side-label">Fees today</span><span class="balance-side-value">PKR {{ number_format($stats['fees'], 2) }}</span></div>
        </div>
    </div>

    <div class="grid grid-4">
        <div class="stat">
            <div class="stat-label">Orders today</div>
            <div class="stat-value">{{ $stats['total'] }}</div>
            <div class="stat-note">{{ $stats['success_rate'] }}% success</div>
        </div>
        <div class="stat">
            <div class="stat-label">Pending now</div>
            <div class="stat-value">{{ $stats['pending'] }}</div>
            <div class="stat-note">Awaiting payment</div>
        </div>
        <div class="stat">
            <div class="stat-label">Completed today</div>
            <div class="stat-value" style="color: var(--success)">{{ $stats['paid'] }}</div>
            <div class="stat-note">Net PKR {{ number_format($stats['net'], 0) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Failed / expired today</div>
            <div class="stat-value" style="color: var(--danger)">{{ $stats['failed'] + $stats['expired'] }}</div>
            <div class="stat-note">{{ $stats['failed'] }} failed · {{ $stats['expired'] }} expired</div>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top: 1.4rem">
        <div class="card" id="credentials">
            <div class="card-head"><h2>API credentials</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Merchant ID</dt><dd class="mono">{{ $merchant->merchant_code }}</dd>
                    <dt>API key</dt><dd class="mono">{{ $merchant->api_key }}</dd>
                    <dt>API secret</dt><dd class="mono">sk_••••••••••••••••</dd>
                    <dt>Secret created</dt><dd>{{ $merchant->secret_rotated_at?->format('d M Y, H:i') ?? '—' }}</dd>
                </dl>

                @if (session('revealed_secret'))
                    <div class="alert alert--warning" style="margin-top: 1rem">
                        <strong>Your API secret.</strong> Keep it on your server only; never put it in browser or app code.
                        <div class="secret-box">{{ session('revealed_secret') }}</div>
                    </div>
                @endif

                <form method="POST" action="{{ route('merchant.secret.reveal') }}" class="filters" style="margin-top: 1rem">
                    @csrf
                    <div class="form-row">
                        <label for="reveal_password">Portal password</label>
                        <input id="reveal_password" type="password" name="password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn">Show API secret</button>
                </form>

                <details style="margin-top: 1rem">
                    <summary class="muted">Rotate secret (if it leaked)</summary>
                    <form method="POST" action="{{ route('merchant.secret.rotate') }}" style="margin-top: .75rem">
                        @csrf
                        <div class="form-row">
                            <label for="rotate_password">Portal password</label>
                            <input id="rotate_password" type="password" name="password" required autocomplete="current-password">
                        </div>
                        <div class="form-row">
                            <label class="checkbox"><input type="checkbox" name="confirm" value="1"> The current secret stops working immediately</label>
                        </div>
                        <button type="submit" class="btn btn--danger">Rotate API secret</button>
                    </form>
                </details>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Endpoints</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Base URL</dt><dd class="mono">{{ url('/api/v1') }}</dd>
                    <dt>Create payment</dt><dd class="mono">POST /api/v1/payments</dd>
                    <dt>Payment status</dt><dd class="mono">GET /api/v1/payments/{payment_id}</dd>
                    <dt>Callback URL</dt><dd class="mono">{{ $merchant->callback_url ?: 'not set (send callback_url per payment)' }}</dd>
                    <dt>Signature window</dt><dd>{{ config('gateway.signature_tolerance') }} seconds</dd>
                    <dt>QR expiry</dt><dd>{{ (int) round(app(\App\Support\Settings::class)->get('payin.expiry_seconds') / 60) }} minutes</dd>
                </dl>
                <h3 style="margin-top: 1rem">Required headers</h3>
                <pre class="json">X-Api-Key: {{ $merchant->api_key }}
X-Timestamp: &lt;unix seconds&gt;
X-Signature: hex(HMAC-SHA256(secret, ts + "\n" + METHOD + "\n" + URI + "\n" + body))
Idempotency-Key: &lt;unique per order attempt&gt;   (POST only)</pre>
            </div>
        </div>
    </div>

    <div class="card" id="telegram">
        <div class="card-head"><h2>Telegram notifications</h2></div>
        <div class="card-body">
            @if ($merchant->isTelegramLinked())
                <p class="mt-0">Connected ✅ @if ($merchant->telegram_username)<span class="mono">@{{ $merchant->telegram_username }}</span>@endif · since {{ $merchant->telegram_linked_at?->timezone('Asia/Karachi')->format('d M Y, H:i') }}</p>
                <p class="muted">You receive payment, callback and withdrawal alerts there, and can send <span class="mono">/balance</span>, <span class="mono">/txns</span>, <span class="mono">/health</span>, <span class="mono">/channels</span>.</p>
                <form method="POST" action="{{ route('merchant.telegram.disconnect') }}" onsubmit="return confirm('Disconnect Telegram? You will stop receiving notifications there.')">
                    @csrf
                    <button type="submit" class="btn btn--danger">Disconnect Telegram</button>
                </form>
            @else
                <p class="muted mt-0">Connect Telegram to get payment / callback / withdrawal alerts and to check your balance and transactions with simple commands — no login needed.</p>
                @if (session('telegram_link'))
                    <div class="alert alert--info">
                        <strong>Open this link in Telegram to finish connecting.</strong> It is valid for 15 minutes.
                        <div style="margin-top:.6rem"><a href="{{ session('telegram_link') }}" target="_blank" rel="noopener" class="btn btn--primary">Open Telegram →</a></div>
                        <div class="mono" style="margin-top:.6rem; word-break:break-all">{{ session('telegram_link') }}</div>
                    </div>
                @else
                    <form method="POST" action="{{ route('merchant.telegram.connect') }}">
                        @csrf
                        <button type="submit" class="btn btn--primary">Connect Telegram</button>
                    </form>
                @endif
            @endif
        </div>
    </div>

    <div class="card" id="ip-allowlist">
        <div class="card-head"><h2>API IP allowlist</h2></div>
        <div class="card-body">
            <p class="muted mt-0">Your API is locked to the IPs below (set by the gateway admin, separately for deposits and withdrawals). Your current IP is <span class="mono">{{ request()->ip() }}</span> — share it with the admin to get access.</p>
            <dl class="kv">
                <dt>Pay-In (deposit)</dt>
                <dd>@if ($merchant->payinIps())<span class="mono">{{ implode(', ', $merchant->payinIps()) }}</span>@else<span class="badge badge--inactive">Empty — pay-in API blocked</span>@endif</dd>
                <dt>Pay-Out (withdrawal)</dt>
                <dd>@if ($merchant->payoutIps())<span class="mono">{{ implode(', ', $merchant->payoutIps()) }}</span>@else<span class="badge badge--inactive">Empty — pay-out API blocked</span>@endif</dd>
            </dl>
            <p class="hint">To add or change an allowed IP, contact your gateway admin.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Recent payments</h2>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Transaction</th><th>Order</th><th class="num">Amount</th><th>Status</th><th>Created</th></tr></thead>
                <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td>
                            <span class="mono">{{ $payment->transaction_id }}</span>
                            <div class="muted mono" style="font-size: .75rem">{{ $payment->payment_id }}</div>
                        </td>
                        <td class="mono">{{ $payment->order_id }}</td>
                        <td class="num">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</td>
                        <td><span class="badge badge--{{ $payment->status->value }}">{{ $payment->status->label() }}</span></td>
                        <td class="nowrap">{{ $payment->created_at->timezone('Asia/Karachi')->format('d M, H:i:s') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No payments yet. <a href="{{ route('merchant.test') }}">Run a test Pay-In</a>.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
