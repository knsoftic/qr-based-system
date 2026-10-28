@extends('layouts.merchant')

@section('title', 'Credentials')

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $merchant->name }}</h1>
            <p>Merchant ID <span class="mono">{{ $merchant->merchant_code }}</span> · use these details to connect your website (<a href="{{ route('docs') }}" target="_blank">API docs</a>).</p>
        </div>
        <div class="actions">
            <a href="{{ route('merchant.test') }}" class="btn btn--primary">Test Pay-In</a>
            <a href="{{ route('merchant.verify') }}" class="btn">Verify integration</a>
        </div>
    </div>

    <div class="grid grid-3">
        <div class="stat">
            <div class="stat-label">Payment requests today</div>
            <div class="stat-value">{{ $stats['today'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Paid today</div>
            <div class="stat-value">{{ $stats['paid_today'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Paid volume today (PKR)</div>
            <div class="stat-value">{{ number_format($stats['volume_today'], 2) }}</div>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top: 1.25rem">
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
