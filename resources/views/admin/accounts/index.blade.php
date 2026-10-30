@extends('layouts.admin')

@section('title', 'Payment Accounts')

@section('content')
    <div class="page-head">
        <div>
            <h1>Payment accounts</h1>
            <p>Authorized QR codes that receive payments. Customers never see which account is used.</p>
        </div>
        <div class="actions">
            <a href="{{ route('admin.accounts.create') }}" class="btn btn--primary"><x-icon name="plus" :size="16" /> Add account</a>
        </div>
    </div>

    @if ($usableCount === 0 && $accounts->total() > 0)
        <div class="alert alert--warning">No account can accept payments right now (all disabled, in maintenance or over their limit). New payment requests will be rejected until one is available.</div>
    @endif

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Account</th>
                    <th>Provider</th>
                    <th class="num">Today / limit</th>
                    <th class="num">This month / limit</th>
                    <th>Last used</th>
                    <th>State</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($accounts as $account)
                    @php($reason = $account->maintenanceReason())
                    <tr>
                        <td>
                            <a href="{{ route('admin.accounts.show', $account) }}"><strong>{{ $account->name }}</strong></a>
                            @if ($account->payload_summary)
                                <div class="muted" style="font-size: .8rem">{{ $account->payload_summary['label'] ?? $account->payload_summary['merchant_name'] ?? '' }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $account->providerName() }}
                            <div><span class="badge badge--{{ $account->decode_status->value }}">{{ $account->decode_status->label() }}</span></div>
                        </td>
                        <td class="num">
                            {{ number_format($account->usedToday(), 0) }}
                            <span class="muted">/ {{ $account->daily_limit !== null ? number_format($account->daily_limit, 0) : '∞' }}</span>
                        </td>
                        <td class="num">
                            {{ number_format($account->usedThisMonth(), 0) }}
                            <span class="muted">/ {{ $account->monthly_limit !== null ? number_format($account->monthly_limit, 0) : '∞' }}</span>
                        </td>
                        <td class="nowrap">{{ $account->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                        <td class="nowrap">
                            @if ($reason)
                                <span class="badge badge--{{ $account->is_active && $account->isDecoded() ? 'warning' : 'inactive' }}">{{ $reason }}</span>
                            @else
                                <span class="badge badge--active">Live</span>
                            @endif
                        </td>
                        <td class="text-right nowrap">
                            <a href="{{ route('admin.accounts.show', $account) }}" class="btn btn--sm">Manage</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="empty">
                            No payment accounts yet. <a href="{{ route('admin.accounts.create') }}">Add your first authorized QR</a>.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $accounts->links() }}
    </div>
@endsection
