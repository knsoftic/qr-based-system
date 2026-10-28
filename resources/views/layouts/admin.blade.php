<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="admin">
    <aside class="sidebar">
        <div class="brand"><span class="brand-mark">QR</span> {{ config('app.name') }}</div>

        <nav class="nav">
            <a href="{{ route('admin.dashboard') }}" @class(['active' => request()->routeIs('admin.dashboard')])>Dashboard</a>
            <a href="{{ route('admin.transactions.index') }}" @class(['active' => request()->routeIs('admin.transactions.*')])>Transactions</a>
            <a href="{{ route('admin.accounts.index') }}" @class(['active' => request()->routeIs('admin.accounts.*')])>Payment Accounts</a>
            <a href="{{ route('admin.merchants.index') }}" @class(['active' => request()->routeIs('admin.merchants.*')])>Merchants &amp; API Keys</a>
            <a href="{{ route('admin.reports') }}" @class(['active' => request()->routeIs('admin.reports')])>Reports</a>

            <div class="nav-section">Settings</div>
            <a href="{{ route('admin.settings.edit', 'payin') }}" @class(['active' => request()->route('tab', 'payin') === 'payin' && request()->routeIs('admin.settings.*')])>Pay-In</a>
            <a href="{{ route('admin.settings.edit', 'payout') }}" @class(['active' => request()->route('tab') === 'payout'])>Pay-Out</a>
            <a href="{{ route('admin.settings.edit', 'fees') }}" @class(['active' => request()->route('tab') === 'fees'])>Fees</a>
            <a href="{{ route('admin.settings.edit', 'api') }}" @class(['active' => request()->route('tab') === 'api'])>API</a>
            <a href="{{ route('admin.settings.edit', 'verification') }}" @class(['active' => request()->route('tab') === 'verification'])>Verification</a>

            <div class="nav-section">Logs</div>
            <a href="{{ route('admin.logs.events') }}" @class(['active' => request()->routeIs('admin.logs.events')])>Transaction Logs</a>
            <a href="{{ route('admin.logs.callbacks') }}" @class(['active' => request()->routeIs('admin.logs.callbacks')])>Callback Logs</a>
            <a href="{{ route('admin.logs.audit') }}" @class(['active' => request()->routeIs('admin.logs.audit')])>Audit Log</a>

            <div class="nav-section">System</div>
            <a href="{{ route('admin.admins.index') }}" @class(['active' => request()->routeIs('admin.admins.*')])>Admin Users</a>
            <a href="{{ route('docs') }}" target="_blank" rel="noopener">API Docs ↗</a>
        </nav>
    </aside>

    <div class="main">
        <header class="topbar">
            <strong>@yield('title', 'Admin')</strong>
            <div class="topbar-user">
                <a href="{{ route('admin.profile.edit') }}">{{ auth()->user()->name }}</a>
                <form method="POST" action="{{ route('admin.logout') }}" class="inline-form">
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
