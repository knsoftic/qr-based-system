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

    @if ($channels->isNotEmpty())
        <div class="card">
            <div class="card-head"><h2>Payment channels</h2></div>
            <div class="card-body">
                <p class="hint mt-0">Put a whole payment method (channel) into maintenance — every account of that channel stops receiving new payments, while other channels and the website keep working.</p>
                <div class="table-wrap">
                <table class="codes-table">
                    <thead><tr><th>Channel</th><th class="num">Accounts</th><th>State</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($channels as $ch)
                        <tr>
                            <td><strong>{{ $ch['name'] }}</strong></td>
                            <td class="num">{{ $ch['active'] }} active / {{ $ch['total'] }}</td>
                            <td>
                                @if ($ch['maintenance'])
                                    <span class="badge badge--warning">In maintenance</span>
                                @else
                                    <span class="badge badge--active">Live</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('admin.accounts.channels.maintenance') }}" class="inline-form"
                                      onsubmit="return confirm('{{ $ch['maintenance'] ? 'Resume' : 'Put in maintenance' }} the “{{ $ch['name'] }}” channel?')">
                                    @csrf
                                    <input type="hidden" name="channel" value="{{ $ch['name'] }}">
                                    <input type="hidden" name="on" value="{{ $ch['maintenance'] ? 0 : 1 }}">
                                    <button type="submit" class="btn btn--sm {{ $ch['maintenance'] ? 'btn--primary' : 'btn--danger' }}">
                                        {{ $ch['maintenance'] ? 'Resume' : 'Maintenance' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Account</th>
                    <th>Channel</th>
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
                        <td>{{ $account->channel ?: '—' }}</td>
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
                        <td colspan="8" class="empty">
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
