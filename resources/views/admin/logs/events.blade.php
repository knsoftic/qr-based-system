@extends('layouts.admin')

@section('title', 'Transaction Logs')

@section('content')
    <div class="page-head">
        <div>
            <h1>Transaction logs</h1>
            <p>Lifecycle events of every payment.</p>
        </div>
        <form method="GET" class="filters">
            <div class="form-row">
                <label for="event">Event</label>
                <select id="event" name="event" onchange="this.form.submit()">
                    <option value="">All events</option>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected(request('event') === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Time</th><th>Transaction</th><th>Event</th><th>Status change</th><th>Actor</th><th>Data</th></tr></thead>
                <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td class="nowrap">{{ $event->created_at->format('d M Y, H:i:s') }}</td>
                        <td>
                            @if ($event->payment)
                                <a href="{{ route('admin.transactions.show', $event->payment) }}" class="mono">{{ $event->payment->transaction_id }}</a>
                            @endif
                        </td>
                        <td>{{ $event->event }}</td>
                        <td class="nowrap">
                            @if ($event->to_status)
                                <span class="badge badge--{{ $event->from_status }}">{{ $event->from_status ?? 'new' }}</span> →
                                <span class="badge badge--{{ $event->to_status }}">{{ $event->to_status }}</span>
                            @endif
                        </td>
                        <td>{{ $event->actor ?? 'system' }}</td>
                        <td class="muted mono" style="font-size: .75rem">{{ $event->data ? json_encode($event->data, JSON_UNESCAPED_SLASHES) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No events yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $events->links() }}
    </div>
@endsection
