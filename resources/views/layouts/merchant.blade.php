<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Merchant portal') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@php($merchant = auth('merchant')->user())
<div class="admin">
    <aside class="sidebar">
        <div class="brand"><span class="brand-mark">QR</span> {{ config('app.name') }}</div>
        <div class="nav-section" style="padding-top: 0">Merchant portal</div>
        <nav class="nav">
            <a href="{{ route('merchant.dashboard') }}" @class(['active' => request()->routeIs('merchant.dashboard')])>Credentials</a>
            <a href="{{ route('merchant.test') }}" @class(['active' => request()->routeIs('merchant.test')])>Test Pay-In</a>
            <a href="{{ route('merchant.verify') }}" @class(['active' => request()->routeIs('merchant.verify')])>Verify integration</a>
            <a href="{{ route('docs') }}" target="_blank" rel="noopener">API docs ↗</a>
        </nav>
    </aside>

    <div class="main">
        <header class="topbar">
            <strong>@yield('title', 'Merchant portal')</strong>
            <div class="topbar-user">
                <span>{{ $merchant->name }} · <span class="mono">{{ $merchant->merchant_code }}</span></span>
                <form method="POST" action="{{ route('merchant.logout') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="btn btn--sm">Log out</button>
                </form>
            </div>
        </header>

        <main class="content">
            @if (session('success'))
                <div class="alert alert--success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert--danger">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert--danger">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
