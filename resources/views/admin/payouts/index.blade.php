@extends('layouts.admin')

@section('title', 'Payouts')

@section('content')
    <div class="page-head">
        <div>
            <h1>Payouts</h1>
            <p>Merchant withdrawal requests. Send the money (easypaisa / JazzCash), then mark it paid.</p>
        </div>
    </div>

    @if ($pendingCount > 0)
        <div class="alert alert--warning"><strong>{{ $pendingCount }} payout(s) awaiting payment</strong> · total PKR {{ number_format($pendingTotal, 2) }}. Send the money, then mark each paid below.</div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="GET" class="filters">
                <div class="form-row">
                    <label for="status">Status</label>
                    <select id="status" name="status" onchange="this.form.submit()">
                        <option value="">All</option>
                        @foreach ($statuses as $key => $label)
                            <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="merchant_id">Merchant</label>
                    <select id="merchant_id" name="merchant_id" onchange="this.form.submit()">
                        <option value="">All</option>
                        @foreach ($merchants as $id => $name)
                            <option value="{{ $id }}" @selected((string) request('merchant_id') === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <a href="{{ route('admin.payouts.index') }}" class="btn">Reset</a>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Ref</th>
                    <th>Merchant</th>
                    <th>Requested</th>
                    <th>Method</th>
                    <th>Send to</th>
                    <th class="num">Amount</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($payouts as $payout)
                    <tr>
                        <td class="mono">{{ $payout->payout_ref }}</td>
                        <td>
                            <a href="{{ route('admin.merchants.show', $payout->merchant) }}">{{ $payout->merchant?->name }}</a>
                            <div class="muted mono" style="font-size: .75rem">{{ $payout->merchant?->merchant_code }}</div>
                        </td>
                        <td class="nowrap">{{ $payout->created_at->timezone('Asia/Karachi')->format('d M, H:i') }}</td>
                        <td>{{ $payout->methodLabel() }}</td>
                        <td>
                            <span class="mono">{{ $payout->account_number }}</span>
                            @if ($payout->account_name)<div class="muted" style="font-size: .8rem">{{ $payout->account_name }}</div>@endif
                            @if ($payout->note)<div class="muted" style="font-size: .75rem">“{{ $payout->note }}”</div>@endif
                        </td>
                        <td class="num"><strong>{{ number_format($payout->amount, 2) }}</strong></td>
                        <td>
                            <span class="badge badge--{{ ['pending' => 'warning', 'paid' => 'success', 'rejected' => 'danger'][$payout->status] }}">{{ $payout->statusLabel() }}</span>
                            @if ($payout->status !== 'pending')
                                <div class="muted" style="font-size: .75rem">{{ $payout->processed_at?->timezone('Asia/Karachi')->format('d M, H:i') }}{{ $payout->processor ? ' · '.$payout->processor->name : '' }}</div>
                                @if ($payout->reference)<div class="muted mono" style="font-size: .75rem">{{ $payout->reference }}</div>@endif
                                @if ($payout->admin_note)<div class="muted" style="font-size: .75rem">{{ $payout->admin_note }}</div>@endif
                            @endif
                        </td>
                        <td class="text-right nowrap">
                            @if ($payout->isPending())
                                <details class="payout-actions">
                                    <summary class="btn btn--sm btn--primary">Mark paid</summary>
                                    <form method="POST" action="{{ route('admin.payouts.paid', $payout) }}" class="payout-pop"
                                          onsubmit="return confirm('Confirm you have sent PKR {{ number_format($payout->amount, 2) }} to {{ $payout->account_number }}?')">
                                        @csrf
                                        <input type="text" name="reference" placeholder="Bank / TID reference (optional)" maxlength="100">
                                        <input type="text" name="admin_note" placeholder="Note (optional)" maxlength="255">
                                        <button type="submit" class="btn btn--sm btn--primary">Confirm paid</button>
                                    </form>
                                </details>
                                <form method="POST" action="{{ route('admin.payouts.reject', $payout) }}" class="inline-form"
                                      onsubmit="return confirm('Reject this payout? The amount returns to the merchant balance.')">
                                    @csrf
                                    <button type="submit" class="btn btn--sm btn--danger">Reject</button>
                                </form>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">No payouts yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $payouts->links() }}
    </div>
@endsection
