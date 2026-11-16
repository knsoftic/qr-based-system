@extends('layouts.merchant')

@section('title', 'Payments')

@section('content')
    <div class="page-head">
        <div>
            <h1>Payments</h1>
            <p>Every pay-in request, its status and amounts. Times are Pakistan time.</p>
        </div>
        <div class="actions">
            <a href="{{ route('merchant.payments.export', request()->query()) }}" class="btn"><x-icon name="download" :size="16" /> Export CSV</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="GET" class="filters">
                <div class="form-row">
                    <label for="q">Search</label>
                    <input id="q" type="text" name="q" value="{{ request('q') }}" placeholder="Order, transaction, payment ID or mobile">
                </div>
                <div class="form-row">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="from">From</label>
                    <input id="from" type="date" name="from" value="{{ request('from') }}">
                </div>
                <div class="form-row">
                    <label for="to">To</label>
                    <input id="to" type="date" name="to" value="{{ request('to') }}">
                </div>
                <button type="submit" class="btn btn--primary">Filter</button>
                @if (request()->hasAny(['q', 'status', 'from', 'to']))
                    <a href="{{ route('merchant.payments') }}" class="btn">Reset</a>
                @endif
            </form>
        </div>
    </div>

    <div class="grid grid-4">
        <div class="stat">
            <div class="stat-label">Payments</div>
            <div class="stat-value">{{ number_format($summary['total']) }}</div>
            <div class="stat-note">{{ number_format($summary['paid']) }} paid · {{ $summary['total'] ? round($summary['paid'] / $summary['total'] * 100, 1) : 0 }}% success</div>
        </div>
        <div class="stat">
            <div class="stat-label">Paid volume</div>
            <div class="stat-value">{{ number_format($summary['volume'], 0) }}</div>
            <div class="stat-note">PKR {{ number_format($summary['volume'], 2) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Fees</div>
            <div class="stat-value">{{ number_format($summary['fees'], 0) }}</div>
            <div class="stat-note">PKR {{ number_format($summary['fees'], 2) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Net received</div>
            <div class="stat-value" style="color: var(--success)">{{ number_format($summary['net'], 0) }}</div>
            <div class="stat-note">PKR {{ number_format($summary['net'], 2) }}</div>
        </div>
    </div>

    <div class="card" style="margin-top: 1.4rem">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Transaction</th>
                    <th>Order</th>
                    <th class="num">Amount</th>
                    <th class="num">Fee</th>
                    <th class="num">Net</th>
                    <th>Status</th>
                    <th>Payer number</th>
                    <th>Created</th>
                    <th>Paid</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td>
                            <span class="mono">{{ $payment->transaction_id }}</span>
                            <div class="muted mono" style="font-size: .75rem">{{ $payment->payment_id }}</div>
                        </td>
                        <td class="mono nowrap">{{ $payment->order_id }}</td>
                        <td class="num nowrap">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</td>
                        <td class="num nowrap muted">{{ number_format($payment->fee_amount, 2) }}</td>
                        <td class="num nowrap">{{ number_format($payment->net_amount, 2) }}</td>
                        <td><span class="badge badge--{{ $payment->status->value }}">{{ $payment->status->label() }}</span></td>
                        <td class="mono nowrap">{{ $payment->payer_phone ?: ($payment->claimed_phone ?: '—') }}</td>
                        <td class="nowrap">{{ $payment->created_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }}</td>
                        <td class="nowrap">{{ $payment->paid_at?->timezone('Asia/Karachi')->format('d M Y, h:i A') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="empty">No payments match. @if (! request()->hasAny(['q', 'status', 'from', 'to']))<a href="{{ route('merchant.test') }}">Run a test Pay-In</a>.@endif</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $payments->links() }}
    </div>
@endsection
