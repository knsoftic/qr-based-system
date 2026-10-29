<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=2">
</head>
<body>
@php
    $admin = auth()->user();
    $initials = collect(explode(' ', $admin->name))->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('');
    $tab = request()->route('tab');
    $links = [
        ['admin.dashboard', 'Dashboard', 'dashboard', request()->routeIs('admin.dashboard')],
        ['admin.transactions.index', 'Transactions', 'transactions', request()->routeIs('admin.transactions.*')],
        ['admin.accounts.index', 'Payment Accounts', 'qr', request()->routeIs('admin.accounts.*')],
        ['admin.merchants.index', 'Merchants & API Keys', 'store', request()->routeIs('admin.merchants.*')],
        ['admin.reports', 'Reports', 'chart', request()->routeIs('admin.reports')],
    ];
    $settings = [
        ['payin', 'Pay-In', 'payin'],
        ['payout', 'Pay-Out', 'payout'],
        ['fees', 'Fees', 'percent'],
        ['api', 'API', 'code'],
        ['verification', 'Verification', 'shield'],
    ];
    $logs = [
        ['admin.logs.events', 'Transaction Logs', 'list'],
        ['admin.logs.callbacks', 'Callback Logs', 'send'],
        ['admin.logs.audit', 'Audit Log', 'clipboard'],
    ];
@endphp
<div class="admin">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">QR</span>
            <span>{{ config('app.name') }}<span class="brand-sub">Admin console</span></span>
        </div>

        <nav class="nav">
            @foreach ($links as [$route, $label, $icon, $active])
                <a href="{{ route($route) }}" @class(['active' => $active])><x-icon :name="$icon" /> {{ $label }}</a>
            @endforeach

            <div class="nav-section">Settings</div>
            @foreach ($settings as [$key, $label, $icon])
                <a href="{{ route('admin.settings.edit', $key) }}" @class(['active' => request()->routeIs('admin.settings.*') && ($tab ?? 'payin') === $key])><x-icon :name="$icon" /> {{ $label }}</a>
            @endforeach

            <div class="nav-section">Logs</div>
            @foreach ($logs as [$route, $label, $icon])
                <a href="{{ route($route) }}" @class(['active' => request()->routeIs($route)])><x-icon :name="$icon" /> {{ $label }}</a>
            @endforeach

            <div class="nav-section">System</div>
            <a href="{{ route('admin.admins.index') }}" @class(['active' => request()->routeIs('admin.admins.*')])><x-icon name="users" /> Admin Users</a>
            <a href="{{ route('docs') }}" target="_blank" rel="noopener"><x-icon name="book" /> API Docs <x-icon name="external" :size="14" class="nav-external" /></a>
        </nav>
    </aside>

    <div class="main">
        <header class="topbar">
            <span class="topbar-title">@yield('title', 'Admin')</span>
            <div class="topbar-user">
                <a href="{{ route('admin.profile.edit') }}" class="user-chip"><span class="avatar">{{ $initials ?: 'A' }}</span> {{ $admin->name }}</a>
                <form method="POST" action="{{ route('admin.logout') }}" class="inline-form">
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
                    Please fix the following:
                    <ul>
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
