@extends('layouts.admin')

@section('title', $isAdmin ? 'Settlements' : 'My earnings')

@php
    $pct = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.').'%';
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $isAdmin ? 'Settlements' : 'My earnings' }}</h1>
            <p>{{ $isAdmin
                ? 'Staff pay for the month: salary + commission on their downline’s paid volume.'
                : 'Your pay for the month: salary + commission on your merchants’ paid volume.' }}</p>
        </div>
        <form method="GET" class="filters">
            <input type="month" name="month" value="{{ $month->format('Y-m') }}" max="{{ now()->format('Y-m') }}" onchange="this.form.submit()">
        </form>
    </div>

    @if ($isAdmin)
        <div class="grid grid-3">
            <div class="stat"><div class="stat-label">Month</div><div class="stat-value">{{ $month->format('M Y') }}</div></div>
            <div class="stat"><div class="stat-label">Staff</div><div class="stat-value">{{ $rows->count() }}</div></div>
            <div class="stat"><div class="stat-label">Total payable</div><div class="stat-value" style="color: var(--success)">PKR {{ number_format($grandTotal, 0) }}</div></div>
        </div>

        <div class="card" style="margin-top: 1.2rem">
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Staff</th>
                        <th>Role</th>
                        <th class="num">Pay-In vol.</th>
                        <th class="num">In %</th>
                        <th class="num">Pay-In comm.</th>
                        <th class="num">Pay-Out vol.</th>
                        <th class="num">Out %</th>
                        <th class="num">Pay-Out comm.</th>
                        <th class="num">Salary</th>
                        <th class="num">Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td><strong>{{ $r['staff']->name }}</strong><div class="muted" style="font-size: .8rem">{{ $r['merchant_count'] }} merchant(s)</div></td>
                            <td><span class="badge badge--{{ $r['staff']->isManager() ? 'warning' : 'inactive' }}">{{ $r['staff']->roleLabel() }}</span></td>
                            <td class="num">{{ number_format($r['payin_volume'], 0) }}</td>
                            <td class="num">{{ $pct($r['payin_rate']) }}</td>
                            <td class="num">{{ number_format($r['payin_commission'], 2) }}</td>
                            <td class="num">{{ number_format($r['payout_volume'], 0) }}</td>
                            <td class="num">{{ $pct($r['payout_rate']) }}</td>
                            <td class="num">{{ number_format($r['payout_commission'], 2) }}</td>
                            <td class="num">{{ number_format($r['salary'], 0) }}</td>
                            <td class="num"><strong>{{ number_format($r['total'], 2) }}</strong></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="empty">No managers or agents yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        @php($me = $rows->first())
        <div class="grid grid-4">
            <div class="stat">
                <div class="stat-label">Pay-In commission</div>
                <div class="stat-value">PKR {{ number_format($me['payin_commission'], 0) }}</div>
                <div class="stat-note">{{ $pct($me['payin_rate']) }} of {{ number_format($me['payin_volume'], 0) }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">Pay-Out commission</div>
                <div class="stat-value">PKR {{ number_format($me['payout_commission'], 0) }}</div>
                <div class="stat-note">{{ $pct($me['payout_rate']) }} of {{ number_format($me['payout_volume'], 0) }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">Salary</div>
                <div class="stat-value">PKR {{ number_format($me['salary'], 0) }}</div>
                <div class="stat-note">{{ $month->format('M Y') }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">Total this month</div>
                <div class="stat-value" style="color: var(--success)">PKR {{ number_format($me['total'], 0) }}</div>
                <div class="stat-note">{{ $me['merchant_count'] }} merchant(s)</div>
            </div>
        </div>
    @endif

    <p class="hint" style="margin-top: 1rem">Commission = rate × paid volume in the selected month. Volume is from merchants in the downline (your own, and a manager’s agents’). Amounts in PKR. Salary is a fixed monthly figure.</p>
@endsection
