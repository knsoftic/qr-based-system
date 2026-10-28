@extends('layouts.admin')

@section('title', 'Transactions')

@section('content')
    <div class="page-head">
        <div>
            <h1>Transactions</h1>
            <p>Every payment request, its status and verification.</p>
        </div>
        <div class="actions">
            <a href="{{ route('admin.transactions.export', request()->query()) }}" class="btn">Export CSV</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="GET" class="filters">
                <div class="form-row">
                    <label for="q">Search</label>
                    <input id="q" type="text" name="q" value="{{ request('q') }}" placeholder="Payment / transaction / order ID">
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
                    <label for="merchant_id">Merchant</label>
                    <select id="merchant_id" name="merchant_id">
                        <option value="">All</option>
                        @foreach ($merchants as $id => $name)
                            <option value="{{ $id }}" @selected((string) request('merchant_id') === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="account_id">Account</label>
                    <select id="account_id" name="account_id">
                        <option value="">All</option>
                        @foreach ($accounts as $id => $name)
                            <option value="{{ $id }}" @selected((string) request('account_id') === (string) $id)>{{ $name }}</option>
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
                <a href="{{ route('admin.transactions.index') }}" class="btn">Reset</a>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Transaction</th>
                    <th>Order</th>
                    <th>Merchant</th>
                    <th>Account</th>
                    <th class="num">Amount</th>
                    <th class="num">Fee</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Expires / paid</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td>
                            <a href="{{ route('admin.transactions.show', $payment) }}" class="mono">{{ $payment->transaction_id }}</a>
                            <div class="muted mono" style="font-size: .75rem">{{ $payment->payment_id }}</div>
                        </td>
                        <td class="mono">{{ $payment->order_id }}</td>
                        <td>{{ $payment->merchant?->name }}</td>
                        <td>{{ $payment->account?->name ?? '—' }}</td>
                        <td class="num">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</td>
                        <td class="num">{{ number_format($payment->fee_amount, 2) }}</td>
                        <td><span class="badge badge--{{ $payment->status->value }}">{{ $payment->status->label() }}</span></td>
                        <td class="nowrap">{{ $payment->created_at->format('d M, H:i:s') }}</td>
                        <td class="nowrap muted">
                            {{ $payment->paid_at ? 'Paid '.$payment->paid_at->format('H:i:s') : $payment->expires_at->format('H:i:s') }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="empty">No transactions match these filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $payments->links() }}
    </div>
@endsection
