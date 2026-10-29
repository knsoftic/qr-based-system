<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin login · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=2">
</head>
<body class="auth-body">
<div class="auth-split">
    <section class="auth-brand">
        <div class="brand"><span class="brand-mark">QR</span> {{ config('app.name') }}</div>

        <div>
            <h1>Gateway admin console</h1>
            <p>Manage payment accounts, merchants, transactions and settings.</p>
            <ul class="auth-points">
                <li><x-icon name="qr" /> Authorized QR accounts, decoded and verified</li>
                <li><x-icon name="transactions" /> Live transactions and manual verification</li>
                <li><x-icon name="clipboard" /> Every admin action is written to the audit log</li>
            </ul>
        </div>

        <div class="auth-foot">Restricted area · all sign-in attempts are logged</div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <div class="brand"><span class="brand-mark">QR</span> {{ config('app.name') }}</div>

            <h2>Admin sign in</h2>
            <p class="lead">Restricted area. All sign-in attempts are logged.</p>

            @if ($errors->any())
                <div class="alert alert--danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.login.attempt') }}">
                @csrf
                <div class="form-row">
                    <label for="email">Email</label>
                    <div class="input-icon">
                        <x-icon name="users" :size="16" />
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                    </div>
                </div>
                <div class="form-row">
                    <label for="password">Password</label>
                    <div class="input-icon">
                        <x-icon name="lock" :size="16" />
                        <input id="password" type="password" name="password" required autocomplete="current-password">
                    </div>
                </div>
                <div class="form-row">
                    <label class="checkbox"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
                </div>
                <button type="submit" class="btn btn--primary btn--block">Sign in</button>
            </form>
        </div>
    </section>
</div>
</body>
</html>
