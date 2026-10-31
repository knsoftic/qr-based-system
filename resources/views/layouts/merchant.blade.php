<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Merchant portal') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=11">
    <script>try{if(localStorage.getItem('rqSidebar')==='mini')document.documentElement.classList.add('sidebar-collapsed');}catch(e){}</script>
</head>
<body>
@php
    $merchant = auth('merchant')->user();
    $initials = collect(explode(' ', $merchant->name))->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('');
    $links = [
        ['merchant.dashboard', 'Dashboard', 'dashboard', request()->routeIs('merchant.dashboard')],
        ['merchant.test', 'Test Pay-In', 'zap', request()->routeIs('merchant.test')],
        ['merchant.payouts', 'Withdraw (Pay-Out)', 'payout', request()->routeIs('merchant.payouts')],
        ['merchant.verify', 'Verify integration', 'check', request()->routeIs('merchant.verify')],
    ];
@endphp
<div class="admin">
    <input type="checkbox" id="nav-toggle" class="nav-toggle" aria-hidden="true">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">QR</span>
            <span class="brand-name">{{ config('app.name') }}<span class="brand-sub">Merchant portal</span></span>
            <label for="nav-toggle" class="nav-burger" aria-label="Toggle menu" role="button">☰</label>
        </div>
        <nav class="nav">
            @foreach ($links as $link)
                <a href="{{ route($link[0]) }}" title="{{ $link[1] }}" @class(['active' => $link[3]])><x-icon :name="$link[2]" /> <span class="nav-label">{{ $link[1] }}</span></a>
            @endforeach
            <div class="nav-section">Help</div>
            <a href="{{ route('docs') }}" target="_blank" rel="noopener" title="API docs"><x-icon name="book" /> <span class="nav-label">API docs</span> <x-icon name="external" :size="14" class="nav-external" /></a>
        </nav>

        <button type="button" class="sidebar-toggle" title="Collapse / expand sidebar">
            <span class="sidebar-toggle-icon">«</span> <span class="nav-label">Collapse</span>
        </button>
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
<script>
    document.querySelector('.sidebar-toggle')?.addEventListener('click', function () {
        var mini = document.documentElement.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem('rqSidebar', mini ? 'mini' : 'full'); } catch (e) {}
    });
</script>
</body>
</html>
