<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Merchant login · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="auth-body">
<div class="auth-card">
    <div class="brand"><span class="brand-mark">QR</span> {{ config('app.name') }}</div>

    <div class="card">
        <div class="card-body">
            <h1>Merchant portal</h1>
            <p class="muted">Sign in with the username and password your gateway admin gave you.</p>

            @if ($errors->any())
                <div class="alert alert--danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('merchant.login.attempt') }}">
                @csrf
                <div class="form-row">
                    <label for="username">Username</label>
                    <input id="username" type="text" name="username" value="{{ old('username') }}" required autofocus autocomplete="username">
                </div>
                <div class="form-row">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn--primary btn--block">Sign in</button>
            </form>
        </div>
    </div>
    <p class="muted text-right"><a href="{{ route('docs') }}">API documentation</a></p>
</div>
</body>
</html>
