@extends('layouts.merchant')

@section('title', 'Payment '.$payment->transaction_id)

@section('content')
    <div class="page-head">
        <div>
            <p class="mt-0"><a href="{{ route('merchant.payments') }}">← All payments</a></p>
            <h1>{{ $payment->currency }} {{ number_format($payment->amount, 2) }}
                <span class="badge badge--{{ $payment->status->value }}" style="vertical-align: middle">{{ $payment->status->label() }}</span>
            </h1>
            <p>Order <span class="mono">{{ $payment->order_id }}</span> · Transaction <span class="mono">{{ $payment->transaction_id }}</span></p>
        </div>
        @if ($canResend)
            <div class="actions">
                <form method="POST" action="{{ route('merchant.payments.callback', $payment->payment_id) }}"
                      onsubmit="return confirm('Send the signed callback to your server again?')">
                    @csrf
                    <button type="submit" class="btn btn--primary"><x-icon name="send" :size="16" /> Resend callback</button>
                </form>
            </div>
        @endif
    </div>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>Payment details</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Amount</dt><dd>{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</dd>
                    <dt>Fee</dt><dd>{{ $payment->currency }} {{ number_format($payment->fee_amount, 2) }}</dd>
                    <dt>Net to you</dt><dd><strong>{{ $payment->currency }} {{ number_format($payment->net_amount, 2) }}</strong></dd>
                    <dt>Status</dt><dd><span class="badge badge--{{ $payment->status->value }}">{{ $payment->status->label() }}</span></dd>
                    <dt>Order ID</dt><dd class="mono">{{ $payment->order_id }}</dd>
                    <dt>Transaction ID</dt><dd class="mono">{{ $payment->transaction_id }}</dd>
                    <dt>Payment ID</dt><dd class="mono">{{ $payment->payment_id }}</dd>
                    @if ($payment->description)
                        <dt>Description</dt><dd>{{ $payment->description }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Payer &amp; verification</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Number entered</dt><dd class="mono">{{ $payment->claimed_phone ?: '—' }}</dd>
                    <dt>Paid from</dt><dd class="mono">{{ $payment->payer_phone ?: '—' }}</dd>
                    <dt>Verified via</dt><dd>{{ $verifiedVia }}</dd>
                    <dt>Bank reference</dt><dd class="mono">{{ $payment->verification_reference ?: '—' }}</dd>
                    <dt>Created</dt><dd>{{ $payment->created_at->timezone('Asia/Karachi')->format('d M Y, h:i:s A') }}</dd>
                    <dt>{{ $payment->isPending() ? 'Expires' : 'QR expiry' }}</dt><dd>{{ $payment->expires_at->timezone('Asia/Karachi')->format('d M Y, h:i:s A') }}</dd>
                    <dt>Paid</dt><dd>{{ $payment->paid_at?->timezone('Asia/Karachi')->format('d M Y, h:i:s A') ?? '—' }}</dd>
                </dl>
                @if ($payment->isPending() && $payment->shortUrl())
                    <p class="hint">Checkout link: <a href="{{ $payment->shortUrl() }}" target="_blank" rel="noopener" class="mono">{{ $payment->shortUrl() }}</a></p>
                @endif
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Timeline</h2></div>
        <div class="card-body">
            <ul class="timeline">
                @forelse ($timeline as $entry)
                    <li>
                        <strong>{{ $entry['label'] }}</strong>
                        @if ($entry['to'])
                            <span class="badge badge--{{ $entry['from'] ?? 'pending' }}">{{ $entry['from'] ?? 'new' }}</span> →
                            <span class="badge badge--{{ $entry['to'] }}">{{ $entry['to'] }}</span>
                        @endif
                        <div class="time">
                            {{ $entry['at']->timezone('Asia/Karachi')->format('d M Y, h:i:s A') }}@if ($entry['note']) · <span class="mono">{{ $entry['note'] }}</span>@endif
                        </div>
                    </li>
                @empty
                    <li><span class="muted">No events recorded.</span></li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Callbacks to your server</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><th>Time</th><th>URL</th><th>Result</th><th>Response</th></tr></thead>
                <tbody>
                @forelse ($payment->callbackDeliveries as $delivery)
                    <tr>
                        <td>{{ $delivery->attempt }}</td>
                        <td class="nowrap">{{ $delivery->created_at->timezone('Asia/Karachi')->format('d M, h:i:s A') }}</td>
                        <td class="mono">{{ $delivery->url }}</td>
                        <td class="nowrap">
                            <span class="badge badge--{{ $delivery->success ? 'success' : 'danger' }}">
                                {{ $delivery->success ? 'Delivered' : 'Failed' }}{{ $delivery->response_code ? ' · '.$delivery->response_code : '' }}
                            </span>
                            @if ($delivery->duration_ms !== null)<span class="muted">{{ $delivery->duration_ms }} ms</span>@endif
                        </td>
                        <td class="muted">{{ \Illuminate\Support\Str::limit($delivery->error ?: $delivery->response_body, 140) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">
                        @if ($payment->isPending())
                            The callback is sent when this payment completes.
                        @else
                            No callback sent (no callback URL set).
                        @endif
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
