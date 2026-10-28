@extends('layouts.admin')

@section('title', 'Reports')

@section('content')
    <div class="page-head">
        <div>
            <h1>Pay-In report</h1>
            <p>{{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}</p>
        </div>
        <form method="GET" class="filters">
            <div class="form-row">
                <label for="from">From</label>
                <input id="from" type="date" name="from" value="{{ $from->toDateString() }}">
            </div>
            <div class="form-row">
                <label for="to">To</label>
                <input id="to" type="date" name="to" value="{{ $to->toDateString() }}">
            </div>
            <button type="submit" class="btn btn--primary">Apply</button>
            <button type="submit" name="export" value="csv" class="btn">Export CSV</button>
        </form>
    </div>

    <div class="grid grid-4">
        <div class="stat">
            <div class="stat-label">Paid volume</div>
            <div class="stat-value">{{ number_format($totals['volume'], 2) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Fees earned</div>
            <div class="stat-value">{{ number_format($totals['fees'], 2) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Paid / requests</div>
            <div class="stat-value">{{ $totals['paid'] }} / {{ $totals['total'] }}</div>
            <div class="stat-note">{{ $totals['total'] ? round($totals['paid'] / $totals['total'] * 100, 1) : 0 }}% conversion</div>
        </div>
        <div class="stat">
            <div class="stat-label">Expired QR codes</div>
            <div class="stat-value">{{ $totals['expired'] }}</div>
        </div>
    </div>

    <div class="card" style="margin-top: 1.25rem">
        <div class="card-head"><h2>Daily breakdown</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Date</th><th class="num">Requests</th><th class="num">Paid</th><th class="num">Expired</th><th class="num">Paid volume</th><th class="num">Fees</th></tr></thead>
                <tbody>
                @forelse ($daily as $row)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($row->day)->format('D, d M Y') }}</td>
                        <td class="num">{{ $row->total }}</td>
                        <td class="num">{{ $row->paid }}</td>
                        <td class="num">{{ $row->expired }}</td>
                        <td class="num">{{ number_format($row->volume, 2) }}</td>
                        <td class="num">{{ number_format($row->fees, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No payments in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-2">
        @foreach (['By merchant' => $byMerchant, 'By payment account' => $byAccount] as $title => $rows)
            <div class="card">
                <div class="card-head"><h2>{{ $title }}</h2></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Name</th><th class="num">Paid</th><th class="num">Volume</th><th class="num">Fees</th></tr></thead>
                        <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td>{{ $row->name }}</td>
                                <td class="num">{{ $row->paid }}</td>
                                <td class="num">{{ number_format($row->volume, 2) }}</td>
                                <td class="num">{{ number_format($row->fees, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="empty">No paid payments.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
@endsection
