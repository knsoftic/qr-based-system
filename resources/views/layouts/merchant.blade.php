<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Merchant portal') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=7">
</head>
<body>
@php
    $merchant = auth('merchant')->user();
    $initials = collect(explode(' ', $merchant->name))->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('');
@endphp
<div class="admin">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">QR</span>
            <span>{{ config('app.name') }}<span class="brand-sub">Merchant portal</span></span>
        </div>
        <nav class="nav">
            <a href="{{ route('merchant.dashboard') }}" @class(['active' => request()->routeIs('merchant.dashboard')])><x-icon name="dashboard" /> Dashboard</a>
            <a href="{{ route('merchant.test') }}" @class(['active' => request()->routeIs('merchant.test')])><x-icon name="zap" /> Test Pay-In</a>
            <a href="{{ route('merchant.payouts') }}" @class(['active' => request()->routeIs('merchant.payouts')])><x-icon name="payout" /> Withdraw (Pay-Out)</a>
            <a href="{{ route('merchant.verify') }}" @class(['active' => request()->routeIs('merchant.verify')])><x-icon name="check" /> Verify integration</a>
            <div class="nav-section">Help</div>
            <a href="{{ route('docs') }}" target="_blank" rel="noopener"><x-icon name="book" /> API docs <x-icon name="external" :size="14" class="nav-external" /></a>
        </nav>
    </aside>

    <div class="main">
        <header class="topbar">
            <span class="topbar-title">@yield('title', 'Merchant portal')</span>
            <div class="topbar-user">
                <span class="user-chip"><span class="avatar">{{ $initials ?: 'M' }}</span> {{ $merchant->name }} · <span class="mono">{{ $merchant->merchant_code }}</span></span>
                <form method="POST" action="{{ route('merchant.logout') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="btn btn--sm btn--ghost" title="Log out"><x-icon name="logout" :size="16" /> Log out</button>
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
