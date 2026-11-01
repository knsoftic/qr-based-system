@extends('layouts.admin')

@section('title', 'My Profile')

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ auth()->user()->name }}</h1>
            <p>{{ auth()->user()->email }}</p>
        </div>
    </div>

    <div class="card" style="max-width: 520px">
        <div class="card-head"><h2>Change password</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.profile.password') }}">
                @csrf
                @method('PUT')
                <div class="form-row">
                    <label for="current_password">Current password</label>
                    <input id="current_password" type="password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="form-row">
                    <label for="password">New password</label>
                    <input id="password" type="password" name="password" required autocomplete="new-password">
                    <div class="hint">At least 10 characters with letters and numbers. Other sessions are signed out.</div>
                </div>
                <div class="form-row">
                    <label for="password_confirmation">Confirm new password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn--primary">Update password</button>
            </form>
        </div>
    </div>

    <div class="card" id="telegram" style="max-width: 520px">
        <div class="card-head"><h2>Telegram notifications</h2></div>
        <div class="card-body">
            @php $me = auth()->user(); @endphp
            @if ($me->isTelegramLinked())
                <p class="mt-0">Connected ✅ @if ($me->telegram_username)<span class="mono">{{ '@'.$me->telegram_username }}</span>@endif · since {{ $me->telegram_linked_at?->timezone('Asia/Karachi')->format('d M Y, H:i') }}</p>
                <p class="muted">Send <span class="mono">/balance</span>, <span class="mono">/channels</span>, <span class="mono">/health</span> to the bot.</p>
                <form method="POST" action="{{ route('admin.profile.telegram.disconnect') }}" onsubmit="return confirm('Disconnect Telegram?')">
                    @csrf
                    <button type="submit" class="btn btn--danger">Disconnect Telegram</button>
                </form>
            @else
                <p class="muted mt-0">Connect Telegram to get alerts and check your commission / downline with commands.</p>
                @if (session('telegram_link'))
                    <div class="alert alert--info">
                        <strong>Open this link in Telegram to finish connecting.</strong> Valid for 15 minutes.
                        <div style="margin-top:.6rem"><a href="{{ session('telegram_link') }}" target="_blank" rel="noopener" class="btn btn--primary">Open Telegram →</a></div>
                        <div class="mono" style="margin-top:.6rem; word-break:break-all">{{ session('telegram_link') }}</div>
                    </div>
                @elseif (config('services.telegram.bot_username'))
                    <form method="POST" action="{{ route('admin.profile.telegram.connect') }}">
                        @csrf
                        <button type="submit" class="btn btn--primary">Connect Telegram</button>
                    </form>
                @else
                    <p class="muted">Telegram is not set up yet. Ask the super admin to configure it in Settings → Telegram.</p>
                @endif
            @endif
        </div>
    </div>
@endsection
