@extends('layouts.admin')

@section('title', $payment->transaction_id)

@section('content')
    <div class="page-head">
        <div>
            <h1 class="mono">{{ $payment->transaction_id }}</h1>
            <p>
                <span class="badge badge--{{ $payment->status->value }}">{{ $payment->status->label() }}</span>
                · {{ $payment->currency }} {{ number_format($payment->amount, 2) }}
                · {{ $payment->merchant->name }}
            </p>
        </div>
        <div class="actions">
            @if ($payment->isPending())
                <a href="{{ $payment->checkoutUrl() }}" target="_blank" rel="noopener" class="btn">Open checkout ↗</a>
            @endif
            <form method="POST" action="{{ route('admin.transactions.callback', $payment) }}" class="inline-form">
                @csrf
                <button type="submit" class="btn">Resend callback</button>
            </form>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>Payment</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Payment ID</dt><dd class="mono">{{ $payment->payment_id }}</dd>
                    <dt>Transaction ID</dt><dd class="mono">{{ $payment->transaction_id }}</dd>
                    <dt>Order ID</dt><dd class="mono">{{ $payment->order_id }}</dd>
                    <dt>Amount</dt><dd>{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</dd>
                    <dt>Fee / net</dt><dd>{{ number_format($payment->fee_amount, 2) }} / {{ number_format($payment->net_amount, 2) }}</dd>
                    <dt>Account</dt>
                    <dd>
                        @if ($payment->account)
                            <a href="{{ route('admin.accounts.show', $payment->account) }}">{{ $payment->account->name }}</a>
                        @else
                            —
                        @endif
                    </dd>
                    <dt>Created</dt><dd>{{ $payment->created_at->format('d M Y, H:i:s') }}</dd>
                    <dt>QR expires</dt><dd>{{ $payment->expires_at->format('d M Y, H:i:s') }}</dd>
                    <dt>Paid at</dt><dd>{{ $payment->paid_at?->format('d M Y, H:i:s') ?? '—' }}</dd>
                    <dt>Verified by</dt><dd>{{ $payment->verified_by === 'sms' ? 'SMS (auto)' : ($payment->verified_by ?? '—') }}</dd>
                    <dt>Bank reference</dt><dd class="mono">{{ $payment->verification_reference ?? '—' }}</dd>
                    <dt>Payer mobile</dt><dd class="mono">{{ $payment->payer_phone ?? '—' }}</dd>
                    @if ($payment->sms_message_id && $payment->smsMessage)
                        <dt>Verifying SMS</dt>
                        <dd>
                            <a href="{{ route('admin.sms.index', ['sender' => $payment->smsMessage->sender]) }}">from {{ $payment->smsMessage->sender }}</a>,
                            {{ $payment->smsMessage->received_at->timezone('Asia/Karachi')->format('d M, H:i:s') }}
                            @if ($payment->smsMessage->match_score) · {{ $payment->smsMessage->match_score }}% match @endif
                            <div class="muted" style="font-size: .8rem; white-space: pre-line">{{ \Illuminate\Support\Str::limit($payment->smsMessage->body, 160) }}</div>
                        </dd>
                    @endif
                    <dt>Description</dt><dd>{{ $payment->description ?? '—' }}</dd>
                    <dt>Customer</dt>
                    <dd>{{ collect([$payment->customer_name, $payment->customer_email, $payment->customer_phone])->filter()->implode(' · ') ?: '—' }}</dd>
                    <dt>Callback URL</dt><dd class="mono">{{ $payment->callback_url ?: ($payment->merchant->callback_url ?: '—') }}</dd>
                    <dt>Client IP</dt><dd class="mono">{{ $payment->client_ip ?? '—' }}</dd>
                    @if ($payment->metadata)
                        <dt>Metadata</dt><dd><pre class="json">{{ json_encode($payment->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></dd>
                    @endif
                </dl>
            </div>
        </div>

        <div>
            @if (in_array($payment->status->value, ['pending', 'expired']))
                <div class="card">
                    <div class="card-head"><h2>Payment verification</h2></div>
                    <div class="card-body">
                        @if ($payment->status->value === 'expired')
                            <div class="alert alert--warning">This QR expired. Only mark it paid if the money was actually received (late payment).</div>
                        @endif
                        <form method="POST" action="{{ route('admin.transactions.paid', $payment) }}"
                              onsubmit="return confirm('Confirm that {{ $payment->currency }} {{ number_format($payment->amount, 2) }} was received?')">
                            @csrf
                            <div class="form-row">
                                <label for="reference">Bank / wallet reference</label>
                                <input id="reference" type="text" name="reference" maxlength="100" placeholder="From the bank notification">
                            </div>
                            <div class="form-row">
                                <label for="note">Note (optional)</label>
                                <input id="note" type="text" name="note" maxlength="255">
                            </div>
                            <button type="submit" class="btn btn--primary">Mark as paid</button>
                        </form>

                        @if ($payment->isPending())
                            <form method="POST" action="{{ route('admin.transactions.failed', $payment) }}" style="margin-top: .75rem"
                                  onsubmit="return confirm('Mark this payment as failed?')">
                                @csrf
                                <button type="submit" class="btn btn--danger">Mark as failed</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-head"><h2>Transaction log</h2></div>
                <div class="card-body">
                    <ul class="timeline">
                        @foreach ($payment->events as $event)
                            <li>
                                <strong>{{ str_replace('_', ' ', $event->event) }}</strong>
                                @if ($event->to_status)
                                    <span class="badge badge--{{ $event->from_status }}">{{ $event->from_status ?? 'new' }}</span> →
                                    <span class="badge badge--{{ $event->to_status }}">{{ $event->to_status }}</span>
                                @endif
                                <div class="time">{{ $event->created_at->format('d M Y, H:i:s') }} · {{ $event->actor ?? 'system' }} {{ $event->ip ? '· '.$event->ip : '' }}</div>
                                @if ($event->data)
                                    <pre class="json">{{ json_encode($event->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Callback deliveries</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><th>Time</th><th>URL</th><th>Result</th><th>Response</th></tr></thead>
                <tbody>
                @forelse ($payment->callbackDeliveries as $delivery)
                    <tr>
                        <td>{{ $delivery->attempt }}</td>
                        <td class="nowrap">{{ $delivery->created_at->format('d M, H:i:s') }}</td>
                        <td class="mono">{{ $delivery->url }}</td>
                        <td>
                            <span class="badge badge--{{ $delivery->success ? 'success' : 'danger' }}">
                                {{ $delivery->success ? 'Delivered' : 'Failed' }}{{ $delivery->response_code ? ' · '.$delivery->response_code : '' }}
                            </span>
                            @if ($delivery->duration_ms !== null)<span class="muted">{{ $delivery->duration_ms }} ms</span>@endif
                        </td>
                        <td class="muted">{{ \Illuminate\Support\Str::limit($delivery->error ?: $delivery->response_body, 120) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No callbacks sent yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
