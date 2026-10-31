@extends('layouts.admin')

@section('title', $merchant->name.' · Downline')

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $merchant->name }}</h1>
            <p>
                <span class="mono">{{ $merchant->merchant_code }}</span>
                @if ($merchant->agent) · Agent: {{ $merchant->agent->name }}@endif
                · <span class="badge badge--inactive">View only</span>
            </p>
        </div>
        <a href="{{ route('admin.downline.index') }}" class="btn">Back</a>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Transaction</th>
                    <th>Order</th>
                    <th class="num">Amount</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Paid</th>
                    <th>Callback</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($payments as $p)
                    <tr>
                        <td class="mono">{{ $p->transaction_id }}<div class="muted mono" style="font-size: .72rem">{{ $p->payment_id }}</div></td>
                        <td class="mono">{{ $p->order_id }}</td>
                        <td class="num">{{ $p->currency }} {{ number_format($p->amount, 2) }}</td>
                        <td><span class="badge badge--{{ $p->status->value }}">{{ $p->status->label() }}</span></td>
                        <td class="nowrap">{{ $p->created_at->timezone('Asia/Karachi')->format('d M, H:i:s') }}</td>
                        <td class="nowrap">{{ $p->paid_at?->timezone('Asia/Karachi')->format('d M, H:i:s') ?? '—' }}</td>
                        <td class="nowrap">
                            @if ($p->callback_sent_at)
                                <span style="color: var(--success)">✓ {{ $p->callback_sent_at->timezone('Asia/Karachi')->format('d M, H:i') }}</span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">No payments yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $payments->links() }}
    </div>

    <p class="hint" style="margin-top: 1rem">Read-only — no actions are available here. Callback shows when a signed callback was delivered to the merchant.</p>
@endsection
