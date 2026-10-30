@extends('layouts.merchant')

@section('title', 'Withdraw (Pay-Out)')

@section('content')
    <div class="page-head">
        <div>
            <h1>Withdraw (Pay-Out)</h1>
            <p>Request a withdrawal of your balance to easypaisa or JazzCash. An admin sends the money and marks it paid.</p>
        </div>
    </div>

    <div class="grid grid-3">
        <div class="stat">
            <div class="stat-label">Available balance</div>
            <div class="stat-value">{{ number_format($balance['available'], 2) }}</div>
            <div class="stat-note">PKR you can withdraw</div>
        </div>
        <div class="stat">
            <div class="stat-label">On hold</div>
            <div class="stat-value" style="color: var(--warning)">{{ number_format($balance['frozen'], 2) }}</div>
            <div class="stat-note">Withdrawals awaiting payment</div>
        </div>
        <div class="stat">
            <div class="stat-label">Total paid out</div>
            <div class="stat-value" style="color: var(--success)">{{ number_format($balance['paid_out'], 2) }}</div>
            <div class="stat-note">Settled to you</div>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top: 1.4rem">
        <div class="card">
            <div class="card-head"><h2>Request a withdrawal</h2></div>
            <div class="card-body">
                @unless ($enabled)
                    <div class="alert alert--warning">Pay-Out is currently disabled by the admin.</div>
                @endunless
                <form method="POST" action="{{ route('merchant.payouts.request') }}">
                    @csrf
                    <div class="form-row">
                        <label for="method">Method</label>
                        <select id="method" name="method" required @disabled(! $enabled)>
                            @foreach ($methods as $key => $label)
                                <option value="{{ $key }}" @selected(old('method') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-2">
                        <div class="form-row">
                            <label for="account_number">Mobile number</label>
                            <input id="account_number" type="tel" name="account_number" value="{{ old('account_number') }}" inputmode="numeric" maxlength="15" placeholder="03XXXXXXXXX" required @disabled(! $enabled)>
                            <div class="hint">The {{ $methods['easypaisa'] ?? 'wallet' }} / JazzCash number to receive the money.</div>
                        </div>
                        <div class="form-row">
                            <label for="account_name">Account title (optional)</label>
                            <input id="account_name" type="text" name="account_name" value="{{ old('account_name') }}" maxlength="100" @disabled(! $enabled)>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="amount">Amount (PKR)</label>
                        <input id="amount" type="number" name="amount" value="{{ old('amount') }}" min="{{ $min }}" max="{{ $max }}" step="0.01" required @disabled(! $enabled)>
                        <div class="hint">Between {{ number_format($min, 0) }} and {{ number_format($max, 0) }}. Available: PKR {{ number_format($balance['available'], 2) }}.</div>
                    </div>
                    <div class="form-row">
                        <label for="note">Note (optional)</label>
                        <input id="note" type="text" name="note" value="{{ old('note') }}" maxlength="255" @disabled(! $enabled)>
                    </div>
                    <button type="submit" class="btn btn--primary" @disabled(! $enabled)><x-icon name="payout" :size="16" /> Request withdrawal</button>
                </form>
                <p class="hint" style="margin-top: .75rem">When you request a withdrawal, the amount is put on hold (removed from your available balance) until an admin sends the money and marks it paid. If it is rejected, the amount returns to your balance.</p>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>API</h2></div>
            <div class="card-body">
                <p class="muted mt-0">Query your balance and request payouts from your server too (<a href="{{ route('docs') }}#balance" target="_blank">docs</a>):</p>
                <pre class="json">GET  {{ url('/api/v1/balance') }}
POST {{ url('/api/v1/payouts') }}
GET  {{ url('/api/v1/payouts') }}</pre>
                <p class="hint">Same signed headers as the payment API.</p>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Your withdrawals</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Ref</th><th>Requested</th><th>Method</th><th>To number</th><th class="num">Amount</th><th>Status</th><th>Paid / note</th></tr></thead>
                <tbody>
                @forelse ($payouts as $payout)
                    <tr>
                        <td class="mono">{{ $payout->payout_ref }}</td>
                        <td class="nowrap">{{ $payout->created_at->timezone('Asia/Karachi')->format('d M, H:i') }}</td>
                        <td>{{ $payout->methodLabel() }}</td>
                        <td class="mono">{{ $payout->account_number }}</td>
                        <td class="num">{{ number_format($payout->amount, 2) }}</td>
                        <td><span class="badge badge--{{ ['pending' => 'warning', 'paid' => 'success', 'rejected' => 'danger'][$payout->status] }}">{{ $payout->statusLabel() }}</span></td>
                        <td class="muted">
                            @if ($payout->status === 'paid'){{ $payout->processed_at?->timezone('Asia/Karachi')->format('d M, H:i') }}{{ $payout->reference ? ' · '.$payout->reference : '' }}
                            @elseif ($payout->status === 'rejected'){{ $payout->admin_note ?: 'Rejected' }}
                            @else Awaiting payment @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">No withdrawals yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $payouts->links() }}
    </div>
@endsection
