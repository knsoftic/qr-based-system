@extends('layouts.admin')

@section('title', 'Payment Accounts')

@section('content')
    <div class="page-head">
        <div>
            <h1>Payment accounts</h1>
            <p>Authorized QR codes that receive payments. Customers never see which account is used.</p>
        </div>
        <div class="actions">
            <a href="{{ route('admin.accounts.create') }}" class="btn btn--primary">+ Add account</a>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Account</th>
                    <th>Provider</th>
                    <th>QR decode status</th>
                    <th class="num">Priority</th>
                    <th class="num">Paid today</th>
                    <th class="num">Daily limit</th>
                    <th>Last used</th>
                    <th>State</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($accounts as $account)
                    <tr>
                        <td>
                            <a href="{{ route('admin.accounts.show', $account) }}"><strong>{{ $account->name }}</strong></a>
                            @if ($account->payload_summary)
                                <div class="muted" style="font-size: .8rem">{{ $account->payload_summary['label'] ?? $account->payload_summary['merchant_name'] ?? '' }}</div>
                            @endif
                        </td>
                        <td>{{ $account->providerName() }}</td>
                        <td><span class="badge badge--{{ $account->decode_status->value }}">{{ $account->decode_status->label() }}</span></td>
                        <td class="num">{{ $account->priority }}</td>
                        <td class="num">{{ $account->paid_today }} · {{ number_format((float) $account->volume_today, 2) }}</td>
                        <td class="num">{{ $account->daily_limit !== null ? number_format($account->daily_limit, 2) : '—' }}</td>
                        <td class="nowrap">{{ $account->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                        <td>
                            <span class="badge badge--{{ $account->is_active ? 'active' : 'inactive' }}">{{ $account->is_active ? 'Active' : 'Disabled' }}</span>
                        </td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('admin.accounts.toggle', $account) }}" class="inline-form">
                                @csrf
                                <button type="submit" class="btn btn--sm {{ $account->is_active ? 'btn--danger' : '' }}">
                                    {{ $account->is_active ? 'Disable' : 'Enable' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="empty">
                            No payment accounts yet. <a href="{{ route('admin.accounts.create') }}">Upload your first authorized QR</a>.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $accounts->links() }}
    </div>
@endsection
