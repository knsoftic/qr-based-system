@php
    $rtl = $theme->isRtl();
    $L = fn ($ur, $en) => $rtl ? $ur : $en;
    $statusLabels = $rtl
        ? ['pending' => 'زیرِ التوا', 'paid' => 'ادا شدہ', 'expired' => 'میعاد ختم', 'failed' => 'ناکام', 'cancelled' => 'منسوخ']
        : ['pending' => 'Pending', 'paid' => 'Paid', 'expired' => 'Expired', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];
    $t = fn ($k, $d = '') => $theme->get($k, $d);
@endphp
<!DOCTYPE html>
<html lang="{{ $theme->lang() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Pay {{ $payment->currency }} {{ number_format($payment->amount, 2) }} · {{ $payment->merchant->name }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=9">
    @if ($theme->needsGoogleFont())
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;500;700&display=swap" rel="stylesheet">
    @endif
    <style>
        :root { --primary: {{ $theme->primaryColor() }}; --primary-strong: {{ $theme->primaryColor() }}; --brand-gradient: {{ $theme->gradient() }}; }
        @if ($theme->customFontUrl())
        @font-face { font-family: 'CheckoutUrdu'; src: url('{!! $theme->customFontUrl() !!}'); font-display: swap; }
        @endif
        .checkout { font-family: {!! $theme->fontFamily() !!};{{ $rtl && $theme->usesNastaliq() ? ' line-height: 2.1;' : '' }} }
        @if ($theme->usesNastaliq())
        .checkout { font-size: 1.05rem; }
        .checkout .phone-prompt strong { font-size: 1.18rem; line-height: 2; display: block; margin-bottom: .35rem; }
        .checkout .phone-prompt p, .checkout-scan-hint, .checkout-steps, .checkout-tagline { font-size: 1rem; }
        .checkout-stepnav { font-size: .9rem; }
        .checkout-details dt { font-size: .98rem; }
        .checkout-steps li { margin-bottom: .4rem; }
        @endif
        .checkout .mono, .checkout-amount, .checkout-amount .currency, #timer-text, .checkout-details dd,
        .phone-form input, .badge, .checkout-foot { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; line-height: 1.4; }
        [dir="ltr"] { direction: ltr; unicode-bidi: isolate; }
    </style>
</head>
<body class="checkout-body">
<main class="checkout" id="checkout"
      data-status-url="{{ route('checkout.status', $payment->payment_id) }}"
      data-status="{{ $payment->status->value }}"
      data-remaining="{{ $payment->remainingSeconds() }}"
      data-total="{{ max(1, $payment->expires_at->diffInSeconds($payment->created_at, true)) }}"
      data-redirect="{{ $redirectUrl }}">

    <header class="checkout-header">
        @if ($theme->logoUrl())
            <img class="checkout-logo" src="{{ $theme->logoUrl() }}" alt="">
        @endif
        <div class="checkout-merchant" dir="auto">{{ $payment->merchant->name }}</div>
        <div class="checkout-amount" dir="ltr">
            <span class="currency">{{ $payment->currency }}</span>
            {{ number_format($payment->amount, 2) }}
        </div>
        @if ($payment->description)
            <div class="checkout-description" dir="auto">{{ $payment->description }}</div>
        @endif
        @if ($t('tagline'))
            <div class="checkout-tagline">{{ $t('tagline') }}</div>
        @endif
    </header>

    @if ($payment->isPending())
        <div class="checkout-stepnav">
            <span @class(['step', 'active' => $needsPhone, 'done' => ! $needsPhone])><span class="step-num">1</span> {{ $L('نمبر درج کریں', 'Enter number') }}</span>
            <span class="step-line"></span>
            <span @class(['step', 'active' => ! $needsPhone])><span class="step-num">2</span> {{ $L('اسکین و ادائیگی', 'Scan & pay') }}</span>
        </div>

        <section class="checkout-phone" id="phone-step">
            @if ($needsPhone)
                <div class="phone-prompt">
                    <strong>{{ $t('step1_title', $L('پہلا مرحلہ — موبائل نمبر درج کریں', 'Step 1 — Enter the mobile number you will pay from')) }}</strong>
                    <p>{{ $t('step1_desc') }}</p>
                </div>
                <form method="POST" action="{{ route('checkout.phone', $payment->payment_id) }}" class="phone-form">
                    @csrf
                    <input type="tel" name="claimed_phone" dir="ltr" inputmode="numeric" maxlength="15" placeholder="03XXXXXXXXX" value="{{ old('claimed_phone') }}" required autofocus>
                    <button type="submit" class="btn btn--primary">{{ $t('step1_button', $L('QR کوڈ دکھائیں', 'Show QR code')) }} →</button>
                </form>
                @error('claimed_phone')<div class="phone-error">{{ $message }}</div>@enderror
            @else
                <div class="phone-saved">
                    <span><x-icon name="check" :size="16" /> {{ $L('ادائیگی اس نمبر سے', 'Pay from') }} <strong class="mono" dir="ltr">{{ $payment->claimed_phone }}</strong></span>
                    <a href="#" onclick="document.getElementById('phone-form').hidden=false;this.parentElement.hidden=true;return false;">{{ $L('تبدیل کریں', 'Change') }}</a>
                </div>
                <form method="POST" action="{{ route('checkout.phone', $payment->payment_id) }}" id="phone-form" class="phone-form" hidden>
                    @csrf
                    <input type="tel" name="claimed_phone" dir="ltr" inputmode="numeric" maxlength="15" placeholder="03XXXXXXXXX" value="{{ $payment->claimed_phone }}" required>
                    <button type="submit" class="btn btn--primary">{{ $L('محفوظ کریں', 'Save') }}</button>
                </form>
                <p class="checkout-scan-hint">{{ $t('step2_hint', $L('دوسرا مرحلہ — اسکین کریں اور ادائیگی کریں', 'Step 2 — Scan and pay')) }}</p>
            @endif
        </section>
    @endif

    <section class="checkout-qr">
        <div id="qr-frame" @class(['qr-frame', 'is-hidden' => ! $qrSvg || $needsPhone])>
            @if ($qrSvg)
                {!! $qrSvg !!}
            @endif
        </div>

        <div class="qr-state qr-state--expired" id="state-expired" @if ($payment->status->value !== 'expired') hidden @endif>
            <div class="qr-state-icon">⏱</div>
            <h2>{{ $t('expired_title', $L('میعاد ختم', 'QR code expired')) }}</h2>
            <p>{{ $t('expired_text') }}</p>
        </div>

        <div class="qr-state qr-state--paid" id="state-paid" @if ($payment->status->value !== 'paid') hidden @endif>
            <div class="qr-state-icon">✓</div>
            <h2>{{ $t('paid_title', $L('ادائیگی موصول', 'Payment received')) }}</h2>
            <p>{{ $t('paid_text') }}</p>
        </div>

        <div class="qr-state qr-state--failed" id="state-failed" @if (! in_array($payment->status->value, ['failed', 'cancelled'])) hidden @endif>
            <div class="qr-state-icon">✕</div>
            <h2>{{ $t('failed_title', $L('ادائیگی مکمل نہیں', 'Payment not completed')) }}</h2>
            <p>{{ $t('failed_text') }}</p>
        </div>
    </section>

    <section class="checkout-timer" id="timer" @if (! $payment->isPending() || $needsPhone) hidden @endif>
        <div class="timer-row">
            <span>{{ $L('QR کی میعاد', 'QR expires in') }}</span>
            <strong id="timer-text" dir="ltr">--:--</strong>
        </div>
        <div class="timer-bar"><div class="timer-bar-fill" id="timer-fill"></div></div>
    </section>

    <dl class="checkout-details">
        <div>
            <dt>{{ $L('حالت', 'Status') }}</dt>
            <dd><span class="badge badge--{{ $payment->status->value }}" id="status-badge">{{ $statusLabels[$payment->status->value] ?? $payment->status->label() }}</span></dd>
        </div>
        <div>
            <dt>{{ $L('ادائیگی نمبر', 'Payment ID') }}</dt>
            <dd class="mono" dir="ltr">{{ $payment->payment_id }}</dd>
        </div>
        <div>
            <dt>{{ $L('ٹرانزیکشن نمبر', 'Transaction ID') }}</dt>
            <dd class="mono" dir="ltr">{{ $payment->transaction_id }}</dd>
        </div>
        <div>
            <dt>{{ $L('آرڈر', 'Order') }}</dt>
            <dd class="mono" dir="ltr">{{ $payment->order_id }}</dd>
        </div>
    </dl>

    <ol class="checkout-steps" id="steps" @if (! $payment->isPending() || $needsPhone) hidden @endif>
        @forelse ($theme->steps() as $step)
            <li>{{ $step }}</li>
        @empty
            <li>{{ $L('اپنی بینکنگ یا والٹ ایپ کھولیں۔', 'Open your banking or wallet app.') }}</li>
            <li>{{ $L('یہ QR اسکین کریں۔', 'Scan this QR code.') }}</li>
            <li>{{ $L('ادائیگی کی تصدیق کریں۔', 'Confirm the payment.') }}</li>
        @endforelse
    </ol>

    @if ($theme->telegramUrl() || $theme->button())
        <section class="checkout-actions">
            @if ($theme->telegramUrl())
                <a class="checkout-action checkout-action--tg" href="{{ $theme->telegramUrl() }}" target="_blank" rel="noopener">
                    <x-icon name="send" :size="16" /> {{ $t('telegram_label', 'Telegram') }}
                </a>
            @endif
            @if ($btn = $theme->button())
                <a class="checkout-action checkout-action--cta" href="{{ $btn['url'] }}" target="_blank" rel="noopener">{{ $btn['label'] }}</a>
            @endif
        </section>
    @endif

    <p class="checkout-redirect" id="redirect-note" hidden>{{ $L('اسٹور پر واپس جا رہے ہیں…', 'Returning to the store…') }}</p>

    <footer class="checkout-foot"><x-icon name="shield" :size="14" /> {{ $t('footer', $L('محفوظ Raast QR ادائیگی', 'Secure Raast QR payment')) }} · {{ config('app.name') }}</footer>
</main>

<script>
(function () {
    var root = document.getElementById('checkout');
    var statusUrl = root.dataset.statusUrl;
    var status = root.dataset.status;
    var total = parseInt(root.dataset.total, 10);
    var deadline = Date.now() + parseInt(root.dataset.remaining, 10) * 1000;
    var labels = @json($statusLabels);
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
