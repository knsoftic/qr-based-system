@extends('layouts.admin')

@section('title', 'Settings')

@section('content')
    <div class="page-head">
        <div>
            <h1>Settings</h1>
            <p>Changes apply to new payment requests immediately and are recorded in the audit log.</p>
        </div>
    </div>

    <nav class="tabs">
        <a href="{{ route('admin.settings.edit', 'payin') }}" @class(['active' => $tab === 'payin'])>Pay-In</a>
        <a href="{{ route('admin.settings.edit', 'payout') }}" @class(['active' => $tab === 'payout'])>Pay-Out</a>
        <a href="{{ route('admin.settings.edit', 'fees') }}" @class(['active' => $tab === 'fees'])>Fees</a>
        <a href="{{ route('admin.settings.edit', 'api') }}" @class(['active' => $tab === 'api'])>API</a>
        <a href="{{ route('admin.settings.edit', 'verification') }}" @class(['active' => $tab === 'verification'])>Verification</a>
    </nav>

    <div class="card" style="max-width: 720px">
        <div class="card-body">
            @if ($tab === 'verification')
                @if (session('verifier_key'))
                    <div class="alert alert--warning">
                        <strong>Verifier key: copy it now.</strong> Only a hash is stored; it will not be shown again.
                        <div class="secret-box">{{ session('verifier_key') }}</div>
                    </div>
                @endif

                <h2>Verification API</h2>
                <p class="muted">
                    A trusted verifier (for example a bank-notification forwarder) can confirm payments by calling
                    <code>POST {{ url('/api/v1/verifications') }}</code> with the header <code>X-Verifier-Key</code>.
                    Payments can always be verified manually from the transaction page.
                </p>
                <p>Status:
                    @if ($settings['verification.key_hash'])
                        <span class="badge badge--success">Enabled</span>
                    @else
                        <span class="badge badge--inactive">Disabled (no key)</span>
                    @endif
                </p>
                <form method="POST" action="{{ route('admin.settings.verifier-key') }}"
                      onsubmit="return confirm('Generate a new verifier key? The current key stops working.')">
                    @csrf
                    <button type="submit" class="btn btn--primary">{{ $settings['verification.key_hash'] ? 'Regenerate key' : 'Generate key' }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.settings.update', $tab) }}">
                    @csrf
                    @method('PUT')

                    @if ($tab === 'payin')
                        <h2>Pay-In settings</h2>
                        <div class="form-row">
                            <label class="checkbox"><input type="checkbox" name="enabled" value="1" @checked($settings['payin.enabled'])> Accept new payment requests</label>
                        </div>
                        <div class="grid grid-2">
                            <div class="form-row">
                                <label for="min_amount">Minimum amount ({{ $settings['payin.currency'] }})</label>
                                <input id="min_amount" type="number" step="0.01" name="min_amount" value="{{ old('min_amount', $settings['payin.min_amount']) }}" required>
                            </div>
                            <div class="form-row">
                                <label for="max_amount">Maximum amount ({{ $settings['payin.currency'] }})</label>
                                <input id="max_amount" type="number" step="0.01" name="max_amount" value="{{ old('max_amount', $settings['payin.max_amount']) }}" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <label for="expiry_seconds">QR expiry (seconds)</label>
                            <input id="expiry_seconds" type="number" name="expiry_seconds" min="60" max="3600" value="{{ old('expiry_seconds', $settings['payin.expiry_seconds']) }}" required>
                            <div class="hint">Default 300 seconds (5 minutes). After expiry the QR is disabled and the payment is marked expired.</div>
                        </div>
                        <div class="form-row">
                            <label for="routing_strategy">Account routing</label>
                            <select id="routing_strategy" name="routing_strategy">
                                @foreach ($strategies as $key => $label)
                                    <option value="{{ $key }}" @selected($settings['payin.routing_strategy'] === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="hint">Accounts that already have an open payment for the same amount are skipped when possible, so incoming credits match exactly one payment.</div>
                        </div>
                    @elseif ($tab === 'payout')
                        <h2>Pay-Out settings</h2>
                        <div class="alert alert--info">Payouts are processed manually for now. These limits apply to the payout module.</div>
                        <div class="form-row">
                            <label class="checkbox"><input type="checkbox" name="enabled" value="1" @checked($settings['payout.enabled'])> Enable payouts</label>
                        </div>
                        <div class="grid grid-2">
                            <div class="form-row">
                                <label for="min_amount">Minimum payout</label>
                                <input id="min_amount" type="number" step="0.01" name="min_amount" value="{{ old('min_amount', $settings['payout.min_amount']) }}" required>
                            </div>
                            <div class="form-row">
                                <label for="max_amount">Maximum payout</label>
                                <input id="max_amount" type="number" step="0.01" name="max_amount" value="{{ old('max_amount', $settings['payout.max_amount']) }}" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <label for="daily_limit">Daily payout limit</label>
                            <input id="daily_limit" type="number" step="0.01" name="daily_limit" value="{{ old('daily_limit', $settings['payout.daily_limit']) }}" required>
                        </div>
                    @elseif ($tab === 'fees')
                        <h2>Fee settings</h2>
                        <p class="muted">Global fees. A merchant with its own fee values overrides these.</p>
                        <h3>Pay-In fee</h3>
                        <div class="grid grid-2">
                            <div class="form-row">
                                <label for="payin_percent">Percent (%)</label>
                                <input id="payin_percent" type="number" step="0.01" min="0" max="100" name="payin_percent" value="{{ old('payin_percent', $settings['fees.payin_percent']) }}" required>
                            </div>
                            <div class="form-row">
                                <label for="payin_fixed">Fixed amount</label>
                                <input id="payin_fixed" type="number" step="0.01" min="0" name="payin_fixed" value="{{ old('payin_fixed', $settings['fees.payin_fixed']) }}" required>
                            </div>
                        </div>
                        <h3>Pay-Out fee</h3>
                        <div class="grid grid-2">
                            <div class="form-row">
                                <label for="payout_percent">Percent (%)</label>
                                <input id="payout_percent" type="number" step="0.01" min="0" max="100" name="payout_percent" value="{{ old('payout_percent', $settings['fees.payout_percent']) }}" required>
                            </div>
                            <div class="form-row">
                                <label for="payout_fixed">Fixed amount</label>
                                <input id="payout_fixed" type="number" step="0.01" min="0" name="payout_fixed" value="{{ old('payout_fixed', $settings['fees.payout_fixed']) }}" required>
                            </div>
                        </div>
                    @elseif ($tab === 'api')
                        <h2>API settings</h2>
                        <div class="form-row">
                            <label for="rate_limit">Rate limit (requests per minute per API key)</label>
                            <input id="rate_limit" type="number" min="1" max="10000" name="rate_limit" value="{{ old('rate_limit', $settings['api.rate_limit']) }}" required>
                        </div>
                        <p class="muted">
                            Signature window: {{ config('gateway.signature_tolerance') }} seconds (<code>GATEWAY_SIGNATURE_TOLERANCE</code>).
                            Each signature is accepted only once.
                        </p>
                    @endif

                    <button type="submit" class="btn btn--primary">Save settings</button>
                </form>
            @endif
        </div>
    </div>
@endsection
