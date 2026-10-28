<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Payment</th>
            <th>Order</th>
            @unless ($hideMerchant ?? false)<th>Merchant</th>@endunless
            <th class="num">Amount</th>
            <th>Status</th>
            <th>Created</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($payments as $payment)
            <tr>
                <td>
                    <a href="{{ route('admin.transactions.show', $payment) }}" class="mono">{{ $payment->transaction_id }}</a>
                    <div class="muted mono" style="font-size: .75rem">{{ $payment->payment_id }}</div>
                </td>
                <td class="mono">{{ $payment->order_id }}</td>
                @unless ($hideMerchant ?? false)<td>{{ $payment->merchant?->name }}</td>@endunless
                <td class="num">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</td>
                <td><span class="badge badge--{{ $payment->status->value }}">{{ $payment->status->label() }}</span></td>
                <td class="nowrap">{{ $payment->created_at->format('d M, H:i:s') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">No transactions yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
