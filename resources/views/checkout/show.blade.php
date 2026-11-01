@php
    $rtl = $theme->isRtl();
    $statusLabels = [
        'pending' => 'زیرِ التوا · Pending',
        'paid' => 'ادا شدہ · Paid',
        'expired' => 'میعاد ختم · Expired',
        'failed' => 'ناکام · Failed',
        'cancelled' => 'منسوخ · Cancelled',
    ];
    $t = fn ($k, $d = '') => $theme->get($k, $d);

    // Render "اردو · English" (or a single language) as a tidy Urdu + muted
    // English pair. English stays a contiguous substring so it reads cleanly
    // and remains assertable.
    $bi = function (string $str) {
        $p = array_map('trim', explode('·', $str, 2));
        if (count($p) === 2 && $p[0] !== '' && $p[1] !== '') {
            return '<span class="ur">'.e($p[0]).'</span><span class="en" dir="ltr">'.e($p[1]).'</span>';
        }
        $only = trim($str);
        $latin = (bool) preg_match('/[A-Za-z]/', $only);

        return '<span class="'.($latin ? 'en' : 'ur').'"'.($latin ? ' dir="ltr"' : '').'>'.e($only).'</span>';
    };
    // View-controlled bilingual label.
    $pair = fn ($ur, $en) => '<span class="ur">'.e($ur).'</span><span class="en" dir="ltr">'.e($en).'</span>';
@endphp
<!DOCTYPE html>
<html lang="{{ $theme->lang() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Pay {{ $payment->currency }} {{ number_format($payment->amount, 2) }} · {{ $payment->merchant->name }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=11">
    @if ($theme->needsGoogleFont())
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;500;700&display=swap" rel="stylesheet">
    @endif
    <style>
        :root{
            --c-primary: {{ $theme->primaryColor() }};
            --c-accent: {{ $theme->accentColor() }};
            --c-grad: {{ $theme->gradient() }};
            --c-bg:#eceef5; --c-card:#ffffff; --c-ink:#0f172a; --c-muted:#6b7280;
            --c-line:#eceef3; --c-soft:#f5f7fb; --c-soft2:#eef1f8;
            --c-ok:#15a34a; --c-ok-bg:#e9f9ef; --c-ok-bd:#bfe9cd; --c-danger:#e11d48;
            --sans:'Segoe UI',system-ui,-apple-system,Roboto,Helvetica,Arial,sans-serif;
        }
        @if ($theme->customFontUrl())
        @font-face{font-family:'CheckoutUrdu';src:url('{!! $theme->customFontUrl() !!}');font-display:swap;}
        @endif
        *{box-sizing:border-box;}
        html,body{margin:0;}
        .co-body{
            min-height:100vh; background:
                radial-gradient(760px 300px at 50% -120px, rgba(99,102,241,.14), transparent 72%),
                linear-gradient(180deg,#f7f8fc,var(--c-bg));
            padding:20px 16px 40px; font-family:var(--sans); color:var(--c-ink);
            -webkit-font-smoothing:antialiased;
        }
        .ur{font-family:{!! $theme->fontFamily() !!};{{ $theme->usesNastaliq() ? 'line-height:1.9;' : '' }}}
        .en{font-family:var(--sans);}

        .co{max-width:400px;margin:0 auto;background:var(--c-card);border-radius:22px;
            box-shadow:0 24px 60px -28px rgba(15,23,42,.4),0 2px 8px -4px rgba(15,23,42,.12);
            overflow:hidden;}

        /* Header */
        .co-head{position:relative;padding:22px 20px 24px;text-align:center;color:#fff;
            background:var(--c-grad);}
        .co-logo{max-height:40px;max-width:60%;margin:0 auto 10px;display:block;object-fit:contain;}
        .co-merchant{font-size:.72rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;opacity:.85;}
        .co-amount{margin-top:6px;font-size:2.2rem;font-weight:800;line-height:1.05;letter-spacing:-.02em;
            font-variant-numeric:tabular-nums;font-family:var(--sans);}
        .co-amount .cur{font-size:.9rem;font-weight:700;opacity:.8;margin-inline-end:.3em;vertical-align:.35em;}
        .co-desc{margin-top:6px;font-size:.82rem;opacity:.9;}
        .co-tag{margin-top:8px;font-size:.78rem;opacity:.92;}
        .co-tag .en{opacity:.8;}

        /* Body */
        .co-body-pad{padding:18px 20px 20px;}

        /* Steps indicator */
        .co-steps-ind{display:flex;align-items:center;gap:8px;margin-bottom:16px;}
        .co-sp{flex:1;display:flex;align-items:center;gap:8px;font-size:.72rem;color:var(--c-muted);min-width:0;}
        .co-sp b{display:grid;place-items:center;width:22px;height:22px;border-radius:50%;
            background:var(--c-soft2);color:var(--c-muted);font-size:.72rem;font-weight:700;flex:none;font-family:var(--sans);}
        .co-sp .lab{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .co-sp.on{color:var(--c-primary);font-weight:700;}
        .co-sp.on b{background:var(--c-primary);color:#fff;}
        .co-sp.ok{color:var(--c-ok);}
        .co-sp.ok b{background:var(--c-ok);color:#fff;}

        /* Prompt + form */
        .co-prompt .ur{display:block;font-weight:700;font-size:1.02rem;}
        .co-prompt .en{display:block;color:var(--c-muted);font-size:.82rem;margin-top:2px;}
        .co-prompt-desc{margin:8px 0 12px;}
        .co-prompt-desc .ur{display:block;color:#334155;font-size:.9rem;}
        .co-prompt-desc .en{display:block;color:var(--c-muted);font-size:.8rem;margin-top:1px;}
        .co-form{display:flex;flex-direction:column;gap:10px;}
        .co-input{width:100%;padding:13px 14px;border:1.5px solid var(--c-line);border-radius:14px;
            font-size:1.05rem;font-family:var(--sans);letter-spacing:.04em;background:var(--c-soft);
            color:var(--c-ink);outline:none;transition:border-color .15s,background .15s;text-align:center;}
        .co-input:focus{border-color:var(--c-primary);background:#fff;}
        .co-btn{width:100%;padding:13px 16px;border:0;border-radius:14px;cursor:pointer;
            font-size:1rem;font-weight:700;font-family:var(--sans);color:#fff;background:var(--c-grad);
            box-shadow:0 10px 22px -12px var(--c-primary);transition:transform .08s,filter .15s;}
        .co-btn:active{transform:translateY(1px);}
        .co-err{color:var(--c-danger);font-size:.82rem;margin-top:8px;text-align:center;}

        /* Saved number chip */
        .co-saved{display:flex;align-items:center;justify-content:space-between;gap:10px;
            padding:10px 12px;background:var(--c-ok-bg);border:1px solid var(--c-ok-bd);border-radius:14px;}
        .co-saved .who{display:flex;align-items:center;gap:7px;min-width:0;color:#14532d;font-size:.82rem;}
        .co-saved .who .num{font-family:var(--sans);font-weight:700;letter-spacing:.03em;}
        .co-saved .who .en{color:#166534;}
        .co-saved a{color:var(--c-primary);font-size:.8rem;text-decoration:none;font-weight:700;white-space:nowrap;}
        .co-dot{width:16px;height:16px;border-radius:50%;background:var(--c-ok);color:#fff;display:grid;place-items:center;flex:none;}
        .co-dot svg{width:11px;height:11px;}

        .co-scan-hint{margin:14px 0 2px;text-align:center;}
        .co-scan-hint .ur{color:#334155;font-size:.9rem;}
        .co-scan-hint .en{display:block;color:var(--c-muted);font-size:.78rem;margin-top:1px;}

        /* QR */
        .co-qr-wrap{display:flex;justify-content:center;margin-top:12px;}
        #qr-frame{width:100%;max-width:216px;aspect-ratio:1;padding:12px;background:#fff;
            border:1px solid var(--c-line);border-radius:18px;box-shadow:0 16px 34px -20px rgba(15,23,42,.5);}
        #qr-frame svg,#qr-frame img{width:100%;height:100%;display:block;}
        #qr-frame.is-hidden{display:none;}
        .co-state{text-align:center;padding:14px 6px 4px;}
        .co-state-ic{width:62px;height:62px;border-radius:50%;margin:0 auto 10px;display:grid;place-items:center;font-size:1.7rem;}
        .co-state h2{margin:0 0 4px;font-size:1.05rem;}
        .co-state p{margin:0;color:var(--c-muted);font-size:.85rem;}
        .co-state--expired .co-state-ic{background:var(--c-soft2);color:var(--c-muted);}
        .co-state--paid .co-state-ic{background:var(--c-ok-bg);color:var(--c-ok);box-shadow:0 0 0 8px rgba(21,163,74,.08);}
        .co-state--failed .co-state-ic{background:#fde8ec;color:var(--c-danger);}

        /* Timer */
        .co-timer{margin-top:16px;}
        .co-timer-row{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:.78rem;color:var(--c-muted);}
        .co-timer-row strong{font-family:var(--sans);font-size:1rem;color:var(--c-ink);font-variant-numeric:tabular-nums;}
        .co-bar{height:5px;border-radius:999px;background:var(--c-soft2);margin-top:7px;overflow:hidden;}
        .co-bar-fill{height:100%;border-radius:999px;background:var(--c-grad);width:100%;transition:width .4s linear;}
        .co-timer.is-urgent strong{color:var(--c-danger);}
        .co-timer.is-urgent .co-bar-fill{background:var(--c-danger);}

        /* Details */
        .co-det{margin-top:16px;border:1px solid var(--c-line);border-radius:14px;overflow:hidden;background:var(--c-soft);}
        .co-det-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 14px;}
        .co-det-row+.co-det-row{border-top:1px solid var(--c-line);}
        .co-det-row .k{font-size:.82rem;}
        .co-det-row .k .en{color:var(--c-muted);margin-inline-start:.4em;font-size:.92em;}
        .co-det-row .v{font-family:var(--sans);font-size:.82rem;color:#334155;text-align:end;word-break:break-all;}

        /* How-to steps */
        .co-how{margin:16px 0 2px;padding:0;list-style:none;counter-reset:s;}
        .co-how li{position:relative;padding-inline-start:28px;margin-bottom:7px;color:#475569;font-size:.85rem;counter-increment:s;}
        .co-how li::before{content:counter(s);position:absolute;inset-inline-start:0;top:0;width:20px;height:20px;
            border-radius:50%;background:var(--c-soft2);color:var(--c-primary);font-weight:700;font-size:.72rem;
            display:grid;place-items:center;font-family:var(--sans);}
        .co-how li .en{color:var(--c-muted);margin-inline-start:.4em;font-size:.92em;}

        /* Actions */
        .co-actions{display:flex;gap:10px;margin-top:16px;}
        .co-act{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:11px 12px;
            border-radius:13px;font-weight:700;font-size:.9rem;text-decoration:none;border:1px solid var(--c-line);color:var(--c-ink);background:#fff;}
        .co-act svg{width:16px;height:16px;}
        .co-act--tg{background:#229ed9;color:#fff;border-color:#229ed9;}
        .co-act--cta{background:var(--c-primary);color:#fff;border-color:var(--c-primary);}

        .co-redirect{margin:12px 0 0;text-align:center;color:var(--c-muted);font-size:.82rem;}
        .co-foot{display:flex;align-items:center;justify-content:center;gap:6px;padding:12px 16px;
            border-top:1px solid var(--c-line);background:var(--c-soft);color:var(--c-muted);font-size:.74rem;}
        .co-foot svg{width:13px;height:13px;color:var(--c-ok);}

        @media (max-width:360px){
            .co-body{padding:14px 10px 28px;}
            .co-head{padding:18px 16px 20px;}
            .co-amount{font-size:1.95rem;}
            .co-body-pad{padding:16px 16px 18px;}
            #qr-frame{max-width:190px;}
        }
    </style>
</head>
<body class="co-body">
<main class="co" id="checkout"
      data-status-url="{{ $statusUrl }}"
      data-status="{{ $payment->status->value }}"
      data-remaining="{{ $payment->remainingSeconds() }}"
      data-total="{{ max(1, $payment->expires_at->diffInSeconds($payment->created_at, true)) }}"
      data-redirect="{{ $redirectUrl }}">

    <header class="co-head">
        @if ($theme->logoUrl())
            <img class="co-logo" src="{{ $theme->logoUrl() }}" alt="">
        @endif
        <div class="co-merchant" dir="auto">{{ $payment->merchant->name }}</div>
        <div class="co-amount" dir="ltr"><span class="cur">{{ $payment->currency }}</span>{{ number_format($payment->amount, 2) }}</div>
        @if ($payment->description)
            <div class="co-desc" dir="auto">{{ $payment->description }}</div>
        @endif
        @if ($t('tagline'))
            <div class="co-tag">{!! $bi($t('tagline')) !!}</div>
        @endif
    </header>

    <div class="co-body-pad">
        @if ($payment->isPending())
            <div class="co-steps-ind">
                <span @class(['co-sp', 'on' => $needsPhone, 'ok' => ! $needsPhone])><b>1</b><span class="lab">{!! $pair('نمبر', 'Enter number') !!}</span></span>
                <span @class(['co-sp', 'on' => ! $needsPhone])><b>2</b><span class="lab">{!! $pair('اسکین', 'Scan & pay') !!}</span></span>
            </div>

            <section id="phone-step">
                @if ($needsPhone)
                    <div class="co-prompt">{!! $bi($t('step1_title', 'ادائیگی والا موبائل نمبر · Enter the mobile number you will pay from')) !!}</div>
                    @if ($t('step1_desc'))
                        <div class="co-prompt-desc">{!! $bi($t('step1_desc')) !!}</div>
                    @endif
                    <form method="POST" action="{{ $phoneAction }}" class="co-form">
                        @csrf
                        <input class="co-input" type="tel" name="claimed_phone" dir="ltr" inputmode="numeric" maxlength="15" placeholder="03XXXXXXXXX" value="{{ old('claimed_phone') }}" required autofocus>
                        <button type="submit" class="co-btn">{!! $bi($t('step1_button', 'QR دکھائیں · Show QR')) !!}</button>
                    </form>
                    @error('claimed_phone')<div class="co-err">{{ $message }}</div>@enderror
                @else
                    <div class="co-saved">
                        <span class="who"><span class="co-dot"><x-icon name="check" :size="12" /></span> {!! $pair('اس نمبر سے', 'Pay from') !!} <span class="num" dir="ltr">{{ $payment->claimed_phone }}</span></span>
                        <a href="#" onclick="document.getElementById('phone-form').hidden=false;this.parentElement.hidden=true;return false;">{!! $pair('بدلیں', 'Change') !!}</a>
                    </div>
                    <form method="POST" action="{{ $phoneAction }}" id="phone-form" class="co-form" hidden style="margin-top:10px">
                        @csrf
                        <input class="co-input" type="tel" name="claimed_phone" dir="ltr" inputmode="numeric" maxlength="15" placeholder="03XXXXXXXXX" value="{{ $payment->claimed_phone }}" required>
                        <button type="submit" class="co-btn">{!! $pair('محفوظ', 'Save') !!}</button>
                    </form>
                    <p class="co-scan-hint">{!! $bi($t('step2_hint', 'یہ QR اسکین کر کے ادائیگی کریں · Scan this QR and pay')) !!}</p>
                @endif
            </section>
        @endif

        <section class="co-qr-wrap">
            <div id="qr-frame" @class(['qr-frame', 'is-hidden' => ! $qrSvg || $needsPhone])>
                @if ($qrSvg)
                    {!! $qrSvg !!}
                @endif
            </div>
        </section>

        <div class="co-state co-state--expired" id="state-expired" @if ($payment->status->value !== 'expired') hidden @endif>
            <div class="co-state-ic">⏱</div>
            <h2>{!! $bi($t('expired_title', 'میعاد ختم · QR expired')) !!}</h2>
            <p>{!! $bi($t('expired_text')) !!}</p>
        </div>
        <div class="co-state co-state--paid" id="state-paid" @if ($payment->status->value !== 'paid') hidden @endif>
            <div class="co-state-ic">✓</div>
            <h2>{!! $bi($t('paid_title', 'ادائیگی موصول · Payment received')) !!}</h2>
            <p>{!! $bi($t('paid_text')) !!}</p>
        </div>
        <div class="co-state co-state--failed" id="state-failed" @if (! in_array($payment->status->value, ['failed', 'cancelled'])) hidden @endif>
            <div class="co-state-ic">✕</div>
            <h2>{!! $bi($t('failed_title', 'ادائیگی مکمل نہیں · Payment not completed')) !!}</h2>
            <p>{!! $bi($t('failed_text')) !!}</p>
        </div>

        <section class="co-timer" id="timer" @if (! $payment->isPending() || $needsPhone) hidden @endif>
            <div class="co-timer-row">
                <span>{!! $pair('QR کی میعاد', 'QR expires in') !!}</span>
                <strong id="timer-text" dir="ltr">--:--</strong>
            </div>
            <div class="co-bar"><div class="co-bar-fill" id="timer-fill"></div></div>
        </section>

        <div class="co-det">
            <div class="co-det-row">
                <span class="k">{!! $pair('حالت', 'Status') !!}</span>
                <span class="v"><span class="badge badge--{{ $payment->status->value }}" id="status-badge">{{ $statusLabels[$payment->status->value] ?? $payment->status->label() }}</span></span>
            </div>
            <div class="co-det-row">
                <span class="k">{!! $pair('ٹرانزیکشن', 'Transaction') !!}</span>
                <span class="v" dir="ltr">{{ $payment->transaction_id }}</span>
            </div>
        </div>

        <ol class="co-how" id="steps" @if (! $payment->isPending() || $needsPhone) hidden @endif>
            @forelse ($theme->steps() as $step)
                <li>{!! $bi($step) !!}</li>
            @empty
                <li>{!! $pair('ایپ کھولیں', 'Open your app') !!}</li>
                <li>{!! $pair('QR اسکین کریں', 'Scan the QR') !!}</li>
                <li>{!! $pair('ادائیگی کی تصدیق کریں', 'Confirm the payment') !!}</li>
            @endforelse
        </ol>

        @if ($theme->telegramUrl() || $theme->button())
            <section class="co-actions">
                @if ($theme->telegramUrl())
                    <a class="co-act co-act--tg" href="{{ $theme->telegramUrl() }}" target="_blank" rel="noopener"><x-icon name="send" :size="16" /> {{ $t('telegram_label', 'Telegram') }}</a>
                @endif
                @if ($btn = $theme->button())
                    <a class="co-act co-act--cta" href="{{ $btn['url'] }}" target="_blank" rel="noopener">{{ $btn['label'] }}</a>
                @endif
            </section>
        @endif

        <p class="co-redirect" id="redirect-note" hidden>{!! $pair('واپس جا رہے ہیں', 'Returning…') !!}</p>
    </div>

    <footer class="co-foot"><x-icon name="shield" :size="14" /> {!! $bi($t('footer', 'محفوظ Raast QR · Secure payment')) !!} · {{ config('app.name') }}</footer>
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
