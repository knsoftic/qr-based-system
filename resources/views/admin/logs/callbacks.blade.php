@extends('layouts.admin')

@section('title', 'Callback Logs')

@section('content')
    <div class="page-head">
        <div>
            <h1>Callback logs</h1>
            <p>Signed status notifications sent to merchants. Failed deliveries are retried with backoff.</p>
        </div>
        <div class="actions">
            <a href="{{ route('admin.logs.callbacks') }}" @class(['btn', 'btn--primary' => ! request()->filled('success')])>All</a>
            <a href="{{ route('admin.logs.callbacks', ['success' => 1]) }}" @class(['btn', 'btn--primary' => request('success') === '1'])>Delivered</a>
            <a href="{{ route('admin.logs.callbacks', ['success' => 0]) }}" @class(['btn', 'btn--primary' => request('success') === '0'])>Failed</a>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Time</th><th>Transaction</th><th>Attempt</th><th>URL</th><th>Result</th><th>Details</th></tr></thead>
                <tbody>
                @forelse ($deliveries as $delivery)
                    <tr>
                        <td class="nowrap">{{ $delivery->created_at->format('d M Y, H:i:s') }}</td>
                        <td>
                            @if ($delivery->payment)
                                <a href="{{ route('admin.transactions.show', $delivery->payment) }}" class="mono">{{ $delivery->payment->transaction_id }}</a>
                            @endif
                        </td>
                        <td>{{ $delivery->attempt }}</td>
                        <td class="mono">{{ $delivery->url }}</td>
                        <td>
                            <span class="badge badge--{{ $delivery->success ? 'success' : 'danger' }}">
                                {{ $delivery->success ? 'Delivered' : 'Failed' }}{{ $delivery->response_code ? ' · '.$delivery->response_code : '' }}
                            </span>
                        </td>
                        <td class="muted">{{ \Illuminate\Support\Str::limit($delivery->error ?: $delivery->response_body, 100) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No callback deliveries.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $deliveries->links() }}
    </div>
@endsection
