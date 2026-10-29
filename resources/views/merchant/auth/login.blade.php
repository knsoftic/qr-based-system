<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Merchant login · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=3">
</head>
<body class="auth-body">
<div class="auth-split">
    <section class="auth-brand">
        <div class="brand"><span class="brand-mark">QR</span> {{ config('app.name') }}</div>

        <div>
            <h1>Accept Raast QR payments on your website.</h1>
            <p>Sign in to your merchant account to get your Merchant ID and API keys, check your integration and test a payment with a live QR.</p>
            <ul class="auth-points">
                <li><x-icon name="qr" /> Dynamic QR with the exact amount, valid for {{ (int) round(app(\App\Support\Settings::class)->get('payin.expiry_seconds') / 60) }} minutes</li>
                <li><x-icon name="shield" /> Signed API requests and signed callbacks</li>
                <li><x-icon name="zap" /> Test Pay-In from your portal in one click</li>
            </ul>
        </div>

        <div class="auth-foot">© {{ date('Y') }} {{ config('app.name') }} · Payments via Raast, easypaisa, JazzCash and banks</div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <div class="brand"><span class="brand-mark">QR</span> {{ config('app.name') }}</div>

            <h2>Merchant login</h2>
            <p class="lead">Enter your merchant details to sign in to your account.</p>

            @if ($errors->any())
                <div class="alert alert--danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('merchant.login.attempt') }}">
                @csrf
                <div class="form-row">
                    <label for="username">Merchant username</label>
                    <div class="input-icon">
                        <x-icon name="store" :size="16" />
                        <input id="username" type="text" name="username" value="{{ old('username') }}" required autofocus autocomplete="username" placeholder="e.g. demostore">
                    </div>
                </div>
                <div class="form-row">
                    <label for="password">Password</label>
                    <div class="input-icon">
                        <x-icon name="lock" :size="16" />
                        <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Your portal password">
                    </div>
                </div>
                <button type="submit" class="btn btn--primary btn--block">Sign in to merchant portal</button>
            </form>

            <div class="auth-help">
                No merchant account yet? Contact the gateway admin to get your username and password.
                Integration guide: <a href="{{ route('docs') }}">API documentation</a>.
            </div>
        </div>
    </section>
</div>
</body>
</html>
