@extends('layouts.admin')

@section('title', 'Downline')

@section('content')
    <div class="page-head">
        <div>
            <h1>Downline</h1>
            <p>Read-only tracking of your downline's merchants — orders, success and callbacks. View only.</p>
        </div>
    </div>

    <div class="grid grid-3">
        <div class="stat"><div class="stat-label">Merchants</div><div class="stat-value">{{ $rows->count() }}</div></div>
        <div class="stat"><div class="stat-label">Orders</div><div class="stat-value">{{ number_format($totalOrders) }}</div></div>
        <div class="stat"><div class="stat-label">Paid volume</div><div class="stat-value" style="color: var(--success)">PKR {{ number_format($totalVolume, 0) }}</div></div>
    </div>

    <div class="card" style="margin-top: 1.2rem">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Merchant</th>
                    @if ($showAgent)<th>Agent</th>@endif
                    <th class="num">Orders</th>
                    <th class="num">Paid</th>
                    <th class="num">Pending</th>
                    <th class="num">Failed</th>
                    <th class="num">Success</th>
                    <th class="num">Paid volume</th>
                    <th>Last activity</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>
                            <strong>{{ $row['merchant']->name }}</strong>
                            <div class="muted mono" style="font-size: .75rem">{{ $row['merchant']->merchant_code }}</div>
                        </td>
                        @if ($showAgent)<td>{{ $row['merchant']->agent?->name ?? '—' }}</td>@endif
                        <td class="num">{{ number_format($row['total']) }}</td>
                        <td class="num" style="color: var(--success)">{{ number_format($row['paid']) }}</td>
                        <td class="num">{{ number_format($row['pending']) }}</td>
                        <td class="num" style="color: var(--danger)">{{ number_format($row['failed']) }}</td>
                        <td class="num">{{ $row['success'] }}%</td>
                        <td class="num">{{ number_format($row['volume'], 0) }}</td>
                        <td class="nowrap">{{ $row['last_at'] ? \Illuminate\Support\Carbon::parse($row['last_at'])->diffForHumans() : 'Never' }}</td>
                        <td class="text-right"><a href="{{ route('admin.downline.show', $row['merchant']) }}" class="btn btn--sm">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $showAgent ? 10 : 9 }}" class="empty">No merchants in your downline yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
