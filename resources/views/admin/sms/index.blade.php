@extends('layouts.admin')

@section('title', 'SMS Inbox')

@section('content')
    <div class="page-head">
        <div>
            <h1>SMS inbox</h1>
            <p>SMS forwarded by your phones. Amounts are detected automatically; "possible match" is a hint for manual verification, nothing is changed on its own.</p>
        </div>
        <div class="actions">
            <a href="{{ route('admin.sms.devices') }}" class="btn"><x-icon name="phone" :size="16" /> Devices</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="GET" class="filters">
                <div class="form-row">
                    <label for="device_id">Device</label>
                    <select id="device_id" name="device_id">
                        <option value="">All</option>
                        @foreach ($devices as $id => $name)
                            <option value="{{ $id }}" @selected((string) request('device_id') === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="sender">Sender</label>
                    <input id="sender" type="text" name="sender" value="{{ request('sender') }}" placeholder="e.g. 8558">
                </div>
                <div class="form-row">
                    <label for="from">From</label>
                    <input id="from" type="date" name="from" value="{{ request('from') }}">
                </div>
                <div class="form-row">
                    <label for="to">To</label>
                    <input id="to" type="date" name="to" value="{{ request('to') }}">
                </div>
                <div class="form-row">
                    <label class="checkbox" style="text-transform: none; letter-spacing: 0"><input type="checkbox" name="with_amount" value="1" @checked(request()->boolean('with_amount'))> Only with an amount</label>
                </div>
                <button type="submit" class="btn btn--primary">Filter</button>
                <a href="{{ route('admin.sms.index') }}" class="btn">Reset</a>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Received</th>
                    <th>From</th>
                    <th>Message</th>
                    <th class="num">Amount</th>
                    <th>Possible match</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($messages as $sms)
                    <tr>
                        <td class="nowrap">
                            {{ $sms->received_at->timezone('Asia/Karachi')->format('d M, H:i:s') }}
                            <div class="muted" style="font-size: .75rem">
                                {{ $sms->device?->name }}@if ($sms->sim_slot !== null) · SIM {{ $sms->sim_slot + 1 }}@endif{{ $sms->sim_name ? ' ('.$sms->sim_name.')' : '' }}
                            </div>
                        </td>
                        <td class="mono nowrap">{{ $sms->sender }}</td>
                        <td style="min-width: 320px; white-space: pre-line">{{ $sms->body }}</td>
                        <td class="num">{{ $sms->amount !== null ? number_format($sms->amount, 2) : '—' }}</td>
                        <td class="nowrap">
                            @forelse ($sms->possibleMatches() as $payment)
                                <div style="margin-bottom: .25rem">
                                    <a href="{{ route('admin.transactions.show', $payment) }}" class="mono">{{ $payment->transaction_id }}</a>
                                    <span class="badge badge--{{ $payment->status->value }}">{{ $payment->status->label() }}</span>
                                </div>
                            @empty
                                <span class="muted">—</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No SMS yet. <a href="{{ route('admin.sms.devices') }}">Add a device</a> and install the app on the phone.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $messages->links() }}
    </div>
@endsection
