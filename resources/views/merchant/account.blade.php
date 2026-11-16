@extends('layouts.merchant')

@section('title', 'Account & security')

@section('content')
    <div class="page-head">
        <div>
            <h1>Account &amp; security</h1>
            <p>Your portal login.</p>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>Account</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Merchant</dt><dd>{{ $merchant->name }}</dd>
                    <dt>Merchant ID</dt><dd class="mono">{{ $merchant->merchant_code }}</dd>
                    <dt>Username</dt><dd class="mono">{{ $merchant->username }}</dd>
                    <dt>Last sign-in</dt><dd>{{ $merchant->last_login_at?->timezone('Asia/Karachi')->format('d M Y, h:i A') ?? '—' }}</dd>
                </dl>
                <p class="hint">To change your business name, callback URL or allowed IPs, contact the gateway admin.</p>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Change password</h2></div>
            <div class="card-body">
                <form method="POST" action="{{ route('merchant.account.password') }}">
                    @csrf
                    @method('PUT')
                    <div class="form-row">
                        <label for="current_password">Current password</label>
                        <input id="current_password" type="password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="form-row">
                        <label for="password">New password</label>
                        <input id="password" type="password" name="password" required autocomplete="new-password">
                        <div class="hint">At least 10 characters with letters and numbers.</div>
                    </div>
                    <div class="form-row">
                        <label for="password_confirmation">Confirm new password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                    </div>
                    <p class="hint mt-0">Every other device signed in to this portal is signed out.</p>
                    <button type="submit" class="btn btn--primary">Change password</button>
                </form>
            </div>
        </div>
    </div>
@endsection
