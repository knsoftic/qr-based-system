@php
    $rtl = $theme->isRtl();
    $pair = fn ($ur, $en) => '<span class="ur">'.e($ur).'</span><span class="en" dir="ltr">'.e($en).'</span>';
    $usable = $link->isUsable();
@endphp
<!DOCTYPE html>
<html lang="{{ $theme->lang() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $link->title }} · {{ $link->merchant->name }}</title>
    <meta property="og:title" content="{{ $link->title }} · {{ $link->merchant->name }}">
    <meta property="og:description" content="{{ $link->isOpenAmount() ? 'Pay securely with Raast QR' : 'Pay PKR '.number_format($link->amount, 2).' securely with Raast QR' }}">
    @if ($theme->needsGoogleFont())
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&display=swap" rel="stylesheet">
    @endif
    <style>
        :root{
            --c-primary: {{ $theme->primaryColor() }};
            --c-grad: {{ $theme->gradient() }};
            --c-ink:#0f172a; --c-muted:#6b7280; --c-line:#eceef3; --c-soft:#f5f7fb; --c-danger:#e11d48;
            --sans:'Segoe UI',system-ui,-apple-system,Roboto,Helvetica,Arial,sans-serif;
        }
        @if ($theme->customFontUrl())
        @font-face{font-family:'CheckoutUrdu';src:url('{!! $theme->customFontUrl() !!}');font-display:swap;}
        @endif
        *{box-sizing:border-box;} html,body{margin:0;}
        body{min-height:100vh;padding:20px 16px 40px;font-family:var(--sans);color:var(--c-ink);
            background:radial-gradient(760px 300px at 50% -120px, rgba(99,102,241,.14), transparent 72%), linear-gradient(180deg,#f7f8fc,#eceef5);}
        .ur{font-family:{!! $theme->fontFamily() !!};{{ $theme->usesNastaliq() ? 'line-height:1.9;' : '' }}}
        .en{font-family:var(--sans);}
        .card{max-width:400px;margin:0 auto;background:#fff;border-radius:22px;overflow:hidden;
            box-shadow:0 24px 60px -28px rgba(15,23,42,.4),0 2px 8px -4px rgba(15,23,42,.12);}
        .head{padding:22px 20px 24px;text-align:center;color:#fff;background:var(--c-grad);}
        .logo{max-height:40px;max-width:60%;margin:0 auto 10px;display:block;object-fit:contain;}
        .merchant{font-size:.72rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;opacity:.85;}
        .title{margin-top:8px;font-size:1.05rem;font-weight:700;}
        .amount{margin-top:6px;font-size:2.2rem;font-weight:800;line-height:1.05;letter-spacing:-.02em;font-variant-numeric:tabular-nums;}
        .amount .cur{font-size:.9rem;font-weight:700;opacity:.8;margin-inline-end:.3em;vertical-align:.35em;}
        .body{padding:18px 20px 20px;}
        .desc{margin:0 0 14px;color:#475569;font-size:.9rem;text-align:center;}
        .row{margin-bottom:12px;}
        .row label{display:flex;gap:6px;align-items:baseline;font-size:.82rem;font-weight:700;margin-bottom:6px;}
        .row label .en{color:var(--c-muted);font-weight:600;}
        .row input{width:100%;padding:13px 14px;border:1.5px solid var(--c-line);border-radius:14px;font-size:1.05rem;
            font-family:var(--sans);background:var(--c-soft);color:var(--c-ink);outline:none;}
        .row input:focus{border-color:var(--c-primary);background:#fff;}
        .hint{font-size:.76rem;color:var(--c-muted);margin-top:5px;}
        .btn{width:100%;padding:14px 16px;border:0;border-radius:14px;cursor:pointer;font-size:1rem;font-weight:700;
            font-family:var(--sans);color:#fff;background:var(--c-grad);box-shadow:0 10px 22px -12px var(--c-primary);
            display:flex;gap:8px;justify-content:center;align-items:baseline;}
        .err{margin:0 0 12px;padding:10px 12px;border-radius:12px;background:#fde8ec;color:#9f1239;font-size:.85rem;}
        .off{text-align:center;padding:10px 4px 6px;}
        .off h2{margin:0 0 6px;font-size:1.05rem;}
        .off p{margin:0;color:var(--c-muted);font-size:.88rem;}
        .foot{display:flex;align-items:center;justify-content:center;gap:6px;padding:12px 16px;border-top:1px solid var(--c-line);
            background:var(--c-soft);color:var(--c-muted);font-size:.74rem;}
    </style>
</head>
<body>
<main class="card">
    <header class="head">
        @if ($theme->logoUrl())
            <img class="logo" src="{{ $theme->logoUrl() }}" alt="">
        @endif
        <div class="merchant" dir="auto">{{ $link->merchant->name }}</div>
        <div class="title" dir="auto">{{ $link->title }}</div>
        @unless ($link->isOpenAmount())
            <div class="amount" dir="ltr"><span class="cur">PKR</span>{{ number_format($link->amount, 2) }}</div>
        @endunless
    </header>

    <div class="body">
        @if ($link->description)
            <p class="desc" dir="auto">{{ $link->description }}</p>
        @endif

        @if (! $usable)
            <div class="off">
                <h2>{!! $pair('یہ لنک فعال نہیں', 'This link is not active') !!}</h2>
                <p>{!! $pair('براہِ کرم دکاندار سے رابطہ کریں', 'Please contact the merchant.') !!}</p>
            </div>
        @else
            @if (session('error'))
                <div class="err">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="err">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('paylink.pay', $link->code) }}">
                @csrf
                @if ($link->isOpenAmount())
                    <div class="row">
                        <label for="amount">{!! $pair('رقم', 'Amount (PKR)') !!}</label>
                        <input id="amount" type="number" name="amount" dir="ltr" inputmode="decimal" step="0.01"
                               min="{{ $min }}" max="{{ $max }}" value="{{ old('amount') }}" placeholder="0.00" required autofocus>
                        <div class="hint" dir="ltr">PKR {{ number_format($min, 2) }} – {{ number_format($max, 2) }}</div>
                    </div>
                @endif
                <div class="row">
                    <label for="name">{!! $pair('آپ کا نام', 'Your name (optional)') !!}</label>
                    <input id="name" type="text" name="name" dir="auto" maxlength="100" value="{{ old('name') }}" autocomplete="name">
                </div>
                <button type="submit" class="btn">{!! $pair('ادائیگی کریں', 'Pay now') !!}</button>
            </form>
        @endif
    </div>

    <footer class="foot"><x-icon name="shield" :size="14" /> {!! $pair('محفوظ Raast QR', 'Secure payment') !!}</footer>
</main>
</body>
</html>
