<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=12">
    <script>try{if(localStorage.getItem('rqSidebar')==='mini')document.documentElement.classList.add('sidebar-collapsed');}catch(e){}</script>
</head>
<body>
@php
    $admin = auth()->user();
    $initials = collect(explode(' ', $admin->name))->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('');
    $tab = request()->route('tab');
    $can = fn (string $p) => $admin->hasPermission($p);
    $pendingPayouts = $can('payouts.view') ? \App\Models\Payout::query()->where('status', 'pending')->count() : 0;
    $pendingRequests = $can('requests.review') ? \App\Models\MerchantRequest::query()->where('status', 'pending')->count() : 0;
    $links = [
        ['admin.dashboard', 'Dashboard', 'dashboard', request()->routeIs('admin.dashboard'), null],
        ['admin.transactions.index', 'Transactions', 'transactions', request()->routeIs('admin.transactions.*'), 'transactions.view'],
        ['admin.accounts.index', 'Payment Accounts', 'qr', request()->routeIs('admin.accounts.*'), 'accounts.view'],
        ['admin.merchants.index', 'Merchants & API Keys', 'store', request()->routeIs('admin.merchants.*'), 'merchants.view'],
        ['admin.downline.index', 'Downline', 'users', request()->routeIs('admin.downline.*'), 'downline.view'],
        ['admin.payouts.index', 'Payouts', 'payout', request()->routeIs('admin.payouts.*'), 'payouts.view', $pendingPayouts ?: null],
        ['admin.checkout.edit', 'Checkout Page', 'qr', request()->routeIs('admin.checkout.*'), 'checkout.manage'],
        ['admin.reports', 'Reports', 'chart', request()->routeIs('admin.reports'), 'reports.view'],
        ['admin.settlements.index', 'Settlements', 'percent', request()->routeIs('admin.settlements.*'), 'settlements.view'],
    ];
    $sms = [
        ['admin.sms.index', 'SMS Inbox', 'message', request()->routeIs('admin.sms.index')],
        ['admin.sms.devices', 'SMS Devices', 'phone', request()->routeIs('admin.sms.devices')],
    ];
    $settings = [
        ['payin', 'Pay-In', 'payin'],
        ['payout', 'Pay-Out', 'payout'],
        ['fees', 'Fees', 'percent'],
        ['api', 'API', 'code'],
        ['verification', 'Verification', 'shield'],
    ];
    $logs = [
        ['admin.logs.events', 'Transaction Logs', 'list', request()->routeIs('admin.logs.events')],
        ['admin.logs.callbacks', 'Callback Logs', 'send', request()->routeIs('admin.logs.callbacks')],
        ['admin.logs.audit', 'Audit Log', 'clipboard', request()->routeIs('admin.logs.audit')],
    ];
@endphp
<div class="admin">
    <input type="checkbox" id="nav-toggle" class="nav-toggle" aria-hidden="true">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">QR</span>
            <span class="brand-name">{{ config('app.name') }}<span class="brand-sub">Admin console</span></span>
            <label for="nav-toggle" class="nav-burger" aria-label="Toggle menu" role="button">☰</label>
        </div>

        <nav class="nav">
            @foreach ($links as $link)
                @if (! $link[4] || $can($link[4]))
                    <a href="{{ route($link[0]) }}" title="{{ $link[1] }}" @class(['active' => $link[3]])>
                        <x-icon :name="$link[2]" /> <span class="nav-label">{{ $link[1] }}</span>
                        @if ($link[5] ?? null)<span class="nav-badge">{{ $link[5] }}</span>@endif
                    </a>
                @endif
            @endforeach

            @if ($can('requests.submit') || $can('requests.review'))
                <a href="{{ route('admin.requests.index') }}" title="Account Requests" @class(['active' => request()->routeIs('admin.requests.*')])>
                    <x-icon name="clipboard" /> <span class="nav-label">Account Requests</span>
                    @if ($pendingRequests)<span class="nav-badge">{{ $pendingRequests }}</span>@endif
                </a>
            @endif

            @if ($can('sms.view'))
                <div class="nav-section">SMS forwarder</div>
                @foreach ($sms as $link)
                    <a href="{{ route($link[0]) }}" title="{{ $link[1] }}" @class(['active' => $link[3]])><x-icon :name="$link[2]" /> <span class="nav-label">{{ $link[1] }}</span></a>
                @endforeach
            @endif

            @if ($can('settings.manage'))
                <div class="nav-section">Settings</div>
                @foreach ($settings as [$key, $label, $icon])
                    <a href="{{ route('admin.settings.edit', $key) }}" title="{{ $label }}" @class(['active' => request()->routeIs('admin.settings.*') && ($tab ?? 'payin') === $key])><x-icon :name="$icon" /> <span class="nav-label">{{ $label }}</span></a>
                @endforeach
            @endif

            @if ($can('logs.view'))
                <div class="nav-section">Logs</div>
                @foreach ($logs as $link)
                    <a href="{{ route($link[0]) }}" title="{{ $link[1] }}" @class(['active' => $link[3]])><x-icon :name="$link[2]" /> <span class="nav-label">{{ $link[1] }}</span></a>
                @endforeach
            @endif

            <div class="nav-section">System</div>
            @if ($can('staff.manage'))
                <a href="{{ route('admin.admins.index') }}" title="Staff & roles" @class(['active' => request()->routeIs('admin.admins.*')])><x-icon name="users" /> <span class="nav-label">Staff &amp; Roles</span></a>
            @endif
            <a href="{{ route('docs') }}" target="_blank" rel="noopener" title="API Docs"><x-icon name="book" /> <span class="nav-label">API Docs</span> <x-icon name="external" :size="14" class="nav-external" /></a>
        </nav>

        <button type="button" class="sidebar-toggle" title="Collapse / expand sidebar">
            <span class="sidebar-toggle-icon">«</span> <span class="nav-label">Collapse</span>
        </button>
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
<script>
    document.querySelector('.sidebar-toggle')?.addEventListener('click', function () {
        var mini = document.documentElement.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem('rqSidebar', mini ? 'mini' : 'full'); } catch (e) {}
    });
</script>
</body>
</html>
