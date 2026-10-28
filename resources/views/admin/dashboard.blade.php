@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    <div class="page-head">
        <div>
            <h1>Today at a glance</h1>
            <p>{{ now()->format('l, j F Y') }}</p>
        </div>
        <div class="actions">
            <a href="{{ route('admin.accounts.create') }}" class="btn btn--primary">+ Add payment account</a>
        </div>
    </div>

    <div class="grid grid-4">
        <div class="stat">
            <div class="stat-label">Paid volume today</div>
            <div class="stat-value">{{ number_format($stats['today_volume'], 2) }}</div>
            <div class="stat-note">Fees {{ number_format($stats['today_fees'], 2) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Payments today</div>
            <div class="stat-value">{{ $stats['today_paid'] }} / {{ $stats['today_total'] }}</div>
            <div class="stat-note">{{ $stats['success_rate'] }}% success rate</div>
        </div>
        <div class="stat">
            <div class="stat-label">Open (pending) QR codes</div>
            <div class="stat-value">{{ $stats['pending'] }}</div>
            <div class="stat-note">Expire automatically</div>
        </div>
        <div class="stat">
            <div class="stat-label">Active accounts</div>
            <div class="stat-value">{{ $stats['active_accounts'] }} / {{ $stats['total_accounts'] }}</div>
            <div class="stat-note">{{ $stats['merchants'] }} active merchant(s)</div>
        </div>
    </div>

    @if ($stats['active_accounts'] === 0)
        <div class="alert alert--warning" style="margin-top: 1.25rem">
            No active payment account. New payment requests will be rejected until you
            <a href="{{ route('admin.accounts.create') }}">upload an authorized QR</a> and activate it.
        </div>
    @endif

    <div class="card" style="margin-top: 1.25rem">
        <div class="card-head">
            <h2>Recent transactions</h2>
            <a href="{{ route('admin.transactions.index') }}">View all →</a>
        </div>
        @include('admin.transactions._table', ['payments' => $recent])
    </div>

    @if ($attention->isNotEmpty())
        <div class="card">
            <div class="card-head"><h2>Accounts needing attention</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Account</th><th>QR decode status</th><th>Reason</th></tr></thead>
                    <tbody>
                    @foreach ($attention as $account)
                        <tr>
                            <td><a href="{{ route('admin.accounts.show', $account) }}">{{ $account->name }}</a></td>
                            <td><span class="badge badge--{{ $account->decode_status->value }}">{{ $account->decode_status->label() }}</span></td>
                            <td class="muted">{{ $account->decode_error ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
