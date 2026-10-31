@extends('layouts.admin')

@section('title', 'Merchants')

@section('content')
    <div class="page-head">
        <div>
            <h1>Merchants &amp; API keys</h1>
            <p>Websites that create payment requests through the API.</p>
        </div>
        <div class="actions">
            @if (auth()->user()->hasPermission('merchants.manage'))
                <a href="{{ route('admin.merchants.create') }}" class="btn btn--primary">+ Add merchant</a>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Merchant</th>
                    <th>Merchant ID</th>
                    <th>API key</th>
                    <th>Portal</th>
                    <th>Fees</th>
                    <th class="num">Payments</th>
                    <th class="num">Paid volume</th>
                    <th>State</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($merchants as $merchant)
                    <tr>
                        <td>
                            <a href="{{ route('admin.merchants.show', $merchant) }}"><strong>{{ $merchant->name }}</strong></a>
                            <div class="muted" style="font-size: .8rem">{{ $merchant->website ?: $merchant->email }}</div>
                        </td>
                        <td class="mono">{{ $merchant->merchant_code }}</td>
                        <td class="mono">{{ $merchant->maskedApiKey() }}</td>
                        <td>
                            @if ($merchant->portal_enabled && $merchant->hasPortalLogin())
                                <span class="badge badge--active">{{ $merchant->username }}</span>
                            @else
                                <span class="muted">Off</span>
                            @endif
                        </td>
                        <td>
                            @if ($merchant->fee_percent !== null || $merchant->fee_fixed !== null)
                                {{ $merchant->fee_percent ?? 0 }}% + {{ $merchant->fee_fixed ?? 0 }}
                            @else
                                <span class="muted">Global</span>
                            @endif
                        </td>
                        <td class="num">{{ $merchant->payments_count }}</td>
                        <td class="num">{{ number_format((float) $merchant->paid_volume, 2) }}</td>
                        <td><span class="badge badge--{{ $merchant->is_active ? 'active' : 'inactive' }}">{{ $merchant->is_active ? 'Active' : 'Disabled' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">No merchants yet. <a href="{{ route('admin.merchants.create') }}">Add one</a> to issue API keys.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $merchants->links() }}
    </div>
@endsection
