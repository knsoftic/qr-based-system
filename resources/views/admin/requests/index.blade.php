@extends('layouts.admin')

@section('title', 'Account Requests')

@section('content')
    <div class="page-head">
        <div>
            <h1>Account requests</h1>
            <p>{{ $canReview ? 'Agent requests to create merchant accounts. Approve to create the merchant + login.' : 'Your merchant account-creation requests.' }}</p>
        </div>
        @if ($canSubmit)
            <a href="{{ route('admin.requests.create') }}" class="btn btn--primary"><x-icon name="plus" :size="16" /> New request</a>
        @endif
    </div>

    @if (session('new_secret') && session('new_merchant'))
        @php($nm = session('new_merchant'))
        <div class="alert alert--warning">
            <strong>Merchant "{{ $nm['name'] }}" created.</strong> Give these to the agent now — the API secret is shown only once.
            <dl class="kv" style="margin-top: .5rem">
                <dt>Merchant ID</dt><dd class="mono">{{ $nm['code'] }}</dd>
                <dt>Portal username</dt><dd class="mono">{{ $nm['username'] }}</dd>
            </dl>
            <div style="margin-top: .6rem; font-weight: 600">API secret</div>
            <div class="secret-box">{{ session('new_secret') }}</div>
        </div>
    @endif

    @if ($canReview && $pendingCount > 0)
        <div class="alert alert--warning"><strong>{{ $pendingCount }} request(s) awaiting review.</strong></div>
    @endif

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Ref</th>
                    @if ($canReview)<th>Agent</th>@endif
                    <th>Merchant</th>
                    <th>Login</th>
                    <th class="num">Rates</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($requests as $req)
                    <tr>
                        <td class="mono">{{ $req->request_ref }}</td>
                        @if ($canReview)<td>{{ $req->agent?->name ?? '—' }}</td>@endif
                        <td>
                            <strong>{{ $req->name }}</strong>
                            @if ($req->website)<div class="muted" style="font-size: .8rem">{{ $req->website }}</div>@endif
                            @if ($req->note)<div class="muted" style="font-size: .75rem">“{{ $req->note }}”</div>@endif
                        </td>
                        <td class="mono">{{ $req->username }}</td>
                        <td class="num nowrap">In {{ rtrim(rtrim(number_format((float) $req->payin_rate, 2), '0'), '.') ?: '0' }}% · Out {{ rtrim(rtrim(number_format((float) $req->payout_rate, 2), '0'), '.') ?: '0' }}%</td>
                        <td class="nowrap">{{ $req->created_at->timezone('Asia/Karachi')->format('d M, H:i') }}</td>
                        <td>
                            <span class="badge badge--{{ ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$req->status] }}">{{ $req->statusLabel() }}</span>
                            @if ($req->status !== 'pending')
                                <div class="muted" style="font-size: .75rem">{{ $req->reviewed_at?->timezone('Asia/Karachi')->format('d M, H:i') }}{{ $req->reviewer ? ' · '.$req->reviewer->name : '' }}</div>
                                @if ($req->admin_note)<div class="muted" style="font-size: .75rem">{{ $req->admin_note }}</div>@endif
                                @if ($req->merchant)<div class="muted mono" style="font-size: .75rem">{{ $req->merchant->merchant_code }}</div>@endif
                            @endif
                        </td>
                        <td class="text-right nowrap">
                            @if ($canReview && $req->isPending())
                                <details class="payout-actions">
                                    <summary class="btn btn--sm btn--primary">Approve</summary>
                                    <form method="POST" action="{{ route('admin.requests.approve', $req) }}" class="payout-pop"
                                          onsubmit="return confirm('Create the merchant account for “{{ $req->name }}” with login “{{ $req->username }}”?')">
                                        @csrf
                                        <input type="text" name="admin_note" placeholder="Note (optional)" maxlength="255">
                                        <button type="submit" class="btn btn--sm btn--primary">Create merchant</button>
                                    </form>
                                </details>
                                <form method="POST" action="{{ route('admin.requests.reject', $req) }}" class="inline-form"
                                      onsubmit="return confirm('Reject this request?')">
                                    @csrf
                                    <button type="submit" class="btn btn--sm btn--danger">Reject</button>
                                </form>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $canReview ? 8 : 7 }}" class="empty">No requests yet.@if ($canSubmit) <a href="{{ route('admin.requests.create') }}">Submit one</a>.@endif</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $requests->links() }}
    </div>
@endsection
