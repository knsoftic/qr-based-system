{{-- Test Pay-In card. Params: $action (form URL), $test (PayInTester::present() result or null), $adminLinks (bool) --}}
<div class="card" id="payin-test">
    <div class="card-head">
        <h2>Test Pay-In (API)</h2>
        <span class="muted">Real signed <span class="mono">POST /api/v1/payments</span> with this merchant's API key</span>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ $action }}" class="filters">
            @csrf
            <div class="form-row">
                <label for="test_amount">Amount (PKR)</label>
                <input id="test_amount" type="number" name="amount" min="1" step="0.01" value="{{ old('amount', 10) }}" required>
            </div>
            <button type="submit" class="btn btn--primary">Create test payment</button>
        </form>
        <p class="hint">This is a real payment request: the QR points to one of the active payment accounts, picked at random. Paying it moves real money.</p>

        @if ($test)
            @php($error = $test['response']['error'] ?? null)
            <div class="alert alert--{{ $test['ok'] ? 'success' : 'danger' }}" style="margin-top: 1rem">
                <strong>HTTP {{ $test['status'] }}</strong>
                @if ($test['ok'])
                    : payment created. Scan the QR below or open the checkout page.
                @else
                    : {{ $error['code'] ?? 'error' }}: {{ $error['message'] ?? 'Request failed.' }}
                @endif
            </div>

            <div class="grid grid-2">
                <div>
                    @if ($test['payment'])
                        @php($payment = $test['payment'])
                        @if ($test['qr_svg'])
                            <div class="qr-frame" style="max-width: 240px; margin: 0">{!! $test['qr_svg'] !!}</div>
                        @else
                            <div class="alert alert--warning">This test payment is {{ $payment->status->value }}; its QR is no longer shown. Create a new test.</div>
                        @endif
                        <dl class="kv" style="margin-top: 1rem">
                            <dt>Amount</dt><dd><strong>{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</strong></dd>
                            <dt>Status</dt><dd><span class="badge badge--{{ $payment->status->value }}">{{ $payment->status->label() }}</span></dd>
                            <dt>QR expires</dt><dd>{{ $payment->expires_at->timezone('Asia/Karachi')->format('d M Y, H:i:s') }} (PKT)</dd>
                            <dt>Payment ID</dt><dd class="mono">{{ $payment->payment_id }}</dd>
                            <dt>Transaction ID</dt><dd class="mono">{{ $payment->transaction_id }}</dd>
                            <dt>Order ID</dt><dd class="mono">{{ $payment->order_id }}</dd>
                        </dl>
                        <div class="actions" style="margin-top: .75rem">
                            <a href="{{ $payment->checkoutUrl() }}" target="_blank" rel="noopener" class="btn btn--primary">Open checkout page ↗</a>
                            @if ($adminLinks ?? false)
                                <a href="{{ route('admin.transactions.show', $payment) }}" class="btn">Transaction details</a>
                            @endif
                        </div>
                    @endif
                </div>
                <div>
                    <h3>Request sent</h3>
                    <pre class="json">{{ $test['request']['method'] }} {{ $test['request']['url'] }}
@foreach ($test['request']['headers'] as $name => $value){{ $name }}: {{ $value }}
@endforeach

{{ json_encode(json_decode($test['request']['body']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    <h3 style="margin-top: 1rem">Response</h3>
                    <pre class="json">{{ json_encode($test['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        @endif
    </div>
</div>
