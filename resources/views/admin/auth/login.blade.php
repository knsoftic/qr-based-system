<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin login · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="auth-body">
<div class="auth-card">
    <div class="brand"><span class="brand-mark">QR</span> {{ config('app.name') }}</div>

    <div class="card">
        <div class="card-body">
            <h1>Admin sign in</h1>
            <p class="muted">Restricted area. All sign-in attempts are logged.</p>

            @if ($errors->any())
                <div class="alert alert--danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.login.attempt') }}">
                @csrf
                <div class="form-row">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                </div>
                <div class="form-row">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required autocomplete="current-password">
                </div>
                <div class="form-row">
                    <label class="checkbox"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
                </div>
                <button type="submit" class="btn btn--primary btn--block">Sign in</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
