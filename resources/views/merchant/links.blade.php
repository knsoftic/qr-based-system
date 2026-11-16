@extends('layouts.merchant')

@section('title', 'Payment links')

@section('content')
    <div class="page-head">
        <div>
            <h1>Payment links</h1>
            <p>Get paid without a website: share a link on WhatsApp or print its QR. Every customer who taps Pay gets a fresh QR, so a link never expires on its own.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>New link</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('merchant.links.store') }}">
                @csrf
                <div class="grid grid-2">
                    <div class="form-row">
                        <label for="title">Title</label>
                        <input id="title" type="text" name="title" value="{{ old('title') }}" maxlength="100" required placeholder="e.g. Monthly fee, Order deposit">
                    </div>
                    <div class="form-row">
                        <label for="amount">Amount (PKR)</label>
                        <input id="amount" type="number" name="amount" value="{{ old('amount') }}" step="0.01" min="{{ $min }}" max="{{ $max }}" placeholder="Leave empty: customer enters the amount">
                        <div class="hint">PKR {{ number_format($min, 2) }} – {{ number_format($max, 2) }}. Empty = the customer types the amount.</div>
                    </div>
                </div>
                <div class="grid grid-2">
                    <div class="form-row">
                        <label for="description">Description (optional)</label>
                        <input id="description" type="text" name="description" value="{{ old('description') }}" maxlength="255" placeholder="Shown to the customer">
                    </div>
                    <div class="form-row">
                        <label for="expires_at">Valid until (optional)</label>
                        <input id="expires_at" type="date" name="expires_at" value="{{ old('expires_at') }}">
                        <div class="hint">Empty = no end date. You can deactivate it any time.</div>
                    </div>
                </div>
                <button type="submit" class="btn btn--primary"><x-icon name="plus" :size="16" /> Create link</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Link</th>
                    <th class="num">Amount</th>
                    <th class="num">Paid</th>
                    <th>Status</th>
                    <th>Share</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($links as $link)
                    @php
                        $url = $link->url();
                        $msg = $link->title.($link->isOpenAmount() ? '' : ' — PKR '.number_format($link->amount, 2)).' — '.$url;
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $link->title }}</strong>
                            @if ($link->description)<div class="muted" style="font-size: .8rem">{{ $link->description }}</div>@endif
                            <div class="mono" style="font-size: .78rem; margin-top: .2rem">{{ $url }}</div>
                        </td>
                        <td class="num nowrap">{{ $link->isOpenAmount() ? 'Customer enters' : 'PKR '.number_format($link->amount, 2) }}</td>
                        <td class="num nowrap">
                            {{ number_format($link->paid_count) }} / {{ number_format($link->payments_count) }}
                            <div class="muted" style="font-size: .78rem">PKR {{ number_format((float) $link->paid_volume, 2) }}</div>
                        </td>
                        <td class="nowrap">
                            @if (! $link->is_active)
                                <span class="badge badge--inactive">Inactive</span>
                            @elseif ($link->isExpired())
                                <span class="badge badge--expired">Expired</span>
                            @else
                                <span class="badge badge--active">Active</span>
                            @endif
                            @if ($link->expires_at)
                                <div class="muted" style="font-size: .75rem">until {{ $link->expires_at->timezone('Asia/Karachi')->format('d M Y') }}</div>
                            @endif
                        </td>
                        <td class="nowrap">
                            <button type="button" class="btn btn--sm" data-copy="{{ $url }}">Copy</button>
                            <a class="btn btn--sm" href="https://wa.me/?text={{ rawurlencode($msg) }}" target="_blank" rel="noopener">WhatsApp</a>
                            <a class="btn btn--sm" href="{{ route('merchant.links.qr', $link->id) }}" target="_blank" rel="noopener">QR</a>
                            <a class="btn btn--sm" href="{{ route('merchant.links.qr', ['id' => $link->id, 'download' => 1]) }}" title="Download the QR image"><x-icon name="download" :size="14" /></a>
                        </td>
                        <td class="text-right nowrap">
                            <form method="POST" action="{{ route('merchant.links.toggle', $link->id) }}" class="inline-form">
                                @csrf
                                <button type="submit" class="btn btn--sm {{ $link->is_active ? 'btn--danger' : 'btn--primary' }}">{{ $link->is_active ? 'Deactivate' : 'Activate' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No payment links yet. Create one above and share it on WhatsApp.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $links->links() }}
    </div>

    <script>
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-copy]');
            if (!btn) { return; }
            var text = btn.getAttribute('data-copy');
            var done = function () { var t = btn.textContent; btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = t; }, 1500); };
            if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy this link:', text); }); }
            else { window.prompt('Copy this link:', text); }
        });
    </script>
@endsection
