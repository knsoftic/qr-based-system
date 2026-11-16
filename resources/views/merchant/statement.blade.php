@extends('layouts.merchant')

@section('title', 'Statement')

@section('content')
    <div class="page-head">
        <div>
            <h1>Statement</h1>
            <p>Your balance day by day: payments received, withdrawals and returns. Pakistan time.</p>
        </div>
        <div class="actions">
            <a href="{{ route('merchant.statement.export', ['month' => $month]) }}" class="btn"><x-icon name="download" :size="16" /> Download CSV</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="GET" class="filters">
                <div class="form-row">
                    <label for="month">Month</label>
                    <select id="month" name="month" onchange="this.form.submit()">
                        @foreach ($months as $key => $label)
                            <option value="{{ $key }}" @selected($month === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <noscript><button type="submit" class="btn btn--primary">Show</button></noscript>
            </form>
        </div>
    </div>

    <div class="grid grid-4">
        <div class="stat">
            <div class="stat-label">Opening balance</div>
            <div class="stat-value">{{ number_format($opening, 2) }}</div>
            <div class="stat-note">Start of {{ $months[$month] }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Received (net)</div>
            <div class="stat-value" style="color: var(--success)">+{{ number_format($totals['received'], 2) }}</div>
            <div class="stat-note">{{ number_format($totals['payments']) }} payments</div>
        </div>
        <div class="stat">
            <div class="stat-label">Withdrawals</div>
            <div class="stat-value" style="color: var(--danger)">−{{ number_format($totals['withdrawn'] - $totals['returned'], 2) }}</div>
            <div class="stat-note">{{ number_format($totals['withdrawn'], 2) }} requested · {{ number_format($totals['returned'], 2) }} returned</div>
        </div>
        <div class="stat">
            <div class="stat-label">Closing balance</div>
            <div class="stat-value">{{ number_format($closing, 2) }}</div>
            <div class="stat-note">End of {{ $months[$month] }}</div>
        </div>
    </div>

    <div class="card" style="margin-top: 1.4rem">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Date</th>
                    <th class="num">Payments</th>
                    <th class="num">Received</th>
                    <th class="num">Withdrawn</th>
                    <th class="num">Returned</th>
                    <th class="num">Closing balance</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td class="muted">Opening balance</td><td></td><td></td><td></td><td></td>
                    <td class="num"><strong>{{ number_format($opening, 2) }}</strong></td>
                </tr>
                @forelse ($rows as $row)
                    <tr>
                        <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('D, d M Y') }}</td>
                        <td class="num">{{ $row['payments'] ?: '—' }}</td>
                        <td class="num" style="color: var(--success)">{{ $row['received'] ? '+'.number_format($row['received'], 2) : '—' }}</td>
                        <td class="num" style="color: var(--danger)">{{ $row['withdrawn'] ? '−'.number_format($row['withdrawn'], 2) : '—' }}</td>
                        <td class="num">{{ $row['returned'] ? '+'.number_format($row['returned'], 2) : '—' }}</td>
                        <td class="num"><strong>{{ number_format($row['closing'], 2) }}</strong></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No activity in {{ $months[$month] }}.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="hint">Balance = payments received (after fees) − withdrawals requested + withdrawals returned. A withdrawal is deducted when you request it.</p>
@endsection
