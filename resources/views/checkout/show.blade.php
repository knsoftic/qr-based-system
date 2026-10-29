<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Pay {{ $payment->currency }} {{ number_format($payment->amount, 2) }} · {{ $payment->merchant->name }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=2">
</head>
<body class="checkout-body">
<main class="checkout" id="checkout"
      data-status-url="{{ route('checkout.status', $payment->payment_id) }}"
      data-status="{{ $payment->status->value }}"
      data-remaining="{{ $payment->remainingSeconds() }}"
      data-total="{{ max(1, $payment->expires_at->diffInSeconds($payment->created_at, true)) }}"
      data-redirect="{{ $redirectUrl }}">

    <header class="checkout-header">
        <div class="checkout-merchant">{{ $payment->merchant->name }}</div>
        <div class="checkout-amount">
            <span class="currency">{{ $payment->currency }}</span>
            {{ number_format($payment->amount, 2) }}
        </div>
        @if ($payment->description)
            <div class="checkout-description">{{ $payment->description }}</div>
        @endif
    </header>

    <section class="checkout-qr">
        <div id="qr-frame" @class(['qr-frame', 'is-hidden' => ! $qrSvg])>
            @if ($qrSvg)
                {!! $qrSvg !!}
            @endif
        </div>

        <div class="qr-state qr-state--expired" id="state-expired" @if ($payment->status->value !== 'expired') hidden @endif>
            <div class="qr-state-icon">⏱</div>
            <h2>QR code expired</h2>
            <p>This payment request is no longer valid. Do not pay with an old QR. Return to the store and start a new payment.</p>
        </div>

        <div class="qr-state qr-state--paid" id="state-paid" @if ($payment->status->value !== 'paid') hidden @endif>
            <div class="qr-state-icon">✓</div>
            <h2>Payment received</h2>
            <p>Thank you. Your payment has been confirmed.</p>
        </div>

        <div class="qr-state qr-state--failed" id="state-failed" @if (! in_array($payment->status->value, ['failed', 'cancelled'])) hidden @endif>
            <div class="qr-state-icon">✕</div>
            <h2>Payment not completed</h2>
            <p>This payment was not completed. Please return to the store and try again.</p>
        </div>
    </section>

    <section class="checkout-timer" id="timer" @if (! $payment->isPending()) hidden @endif>
        <div class="timer-row">
            <span>QR expires in</span>
            <strong id="timer-text">--:--</strong>
        </div>
        <div class="timer-bar"><div class="timer-bar-fill" id="timer-fill"></div></div>
    </section>

    <dl class="checkout-details">
        <div>
            <dt>Status</dt>
            <dd><span class="badge badge--{{ $payment->status->value }}" id="status-badge">{{ $payment->status->label() }}</span></dd>
        </div>
        <div>
            <dt>Payment ID</dt>
            <dd class="mono">{{ $payment->payment_id }}</dd>
        </div>
        <div>
            <dt>Transaction ID</dt>
            <dd class="mono">{{ $payment->transaction_id }}</dd>
        </div>
        <div>
            <dt>Order</dt>
            <dd class="mono">{{ $payment->order_id }}</dd>
        </div>
    </dl>

    <ol class="checkout-steps" id="steps" @if (! $payment->isPending()) hidden @endif>
        <li>Open your banking or wallet app.</li>
        <li>Scan this QR code. The amount is filled in automatically.</li>
        <li>Confirm the payment. This page updates on its own.</li>
    </ol>

    <p class="checkout-redirect" id="redirect-note" hidden>Returning to the store…</p>

    <footer class="checkout-foot"><x-icon name="shield" :size="14" /> Secure Raast QR payment · {{ config('app.name') }}</footer>
</main>

<script>
(function () {
    var root = document.getElementById('checkout');
    var statusUrl = root.dataset.statusUrl;
    var status = root.dataset.status;
    var total = parseInt(root.dataset.total, 10);
    var deadline = Date.now() + parseInt(root.dataset.remaining, 10) * 1000;
    var labels = { pending: 'Pending', paid: 'Paid', expired: 'Expired', failed: 'Failed', cancelled: 'Cancelled' };
    var pollTimer = null;

    function $(id) { return document.getElementById(id); }

    function render(next, redirectUrl) {
        status = next;
        var badge = $('status-badge');
        badge.textContent = labels[next] || next;
        badge.className = 'badge badge--' + next;

        var pending = next === 'pending';
        $('qr-frame').classList.toggle('is-hidden', !pending);
        if (!pending) { $('qr-frame').innerHTML = ''; }
        $('timer').hidden = !pending;
        $('steps').hidden = !pending;
        $('state-expired').hidden = next !== 'expired';
        $('state-paid').hidden = next !== 'paid';
        $('state-failed').hidden = !(next === 'failed' || next === 'cancelled');

        if (!pending) {
            clearInterval(pollTimer);
            if (redirectUrl) {
                $('redirect-note').hidden = false;
                setTimeout(function () { window.location.href = redirectUrl; }, 3000);
            }
        }
    }

    function tick() {
        if (status !== 'pending') { return; }
        var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
        var m = Math.floor(left / 60), s = left % 60;
        $('timer-text').textContent = m + ':' + (s < 10 ? '0' : '') + s;
        $('timer-fill').style.width = Math.min(100, (left / total) * 100) + '%';
        $('timer').classList.toggle('is-urgent', left <= 60);
        if (left === 0) {
            render('expired', null);
            poll();
        } else {
            requestAnimationFrame(function () { setTimeout(tick, 250); });
        }
    }

    function poll() {
        fetch(statusUrl, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) { return; }
                if (data.status === 'pending' && status !== 'pending') {
                    // Local clock ran ahead of the server; reload to show the QR again.
                    window.location.reload();
                } else if (data.status === 'pending') {
                    deadline = Date.now() + data.remaining_seconds * 1000;
                } else if (data.status !== status || data.redirect_url) {
                    render(data.status, data.redirect_url);
                }
            })
            .catch(function () {});
    }

    if (status === 'pending') {
        tick();
        pollTimer = setInterval(poll, 4000);
    } else if (root.dataset.redirect) {
        render(status, root.dataset.redirect);
    }
})();
</script>
</body>
</html>
