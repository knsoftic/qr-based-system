@csrf
<div class="grid grid-2">
    <div class="form-row">
        <label for="name">Merchant name</label>
        <input id="name" type="text" name="name" value="{{ old('name', $merchant->name) }}" required maxlength="100">
        <div class="hint">Shown to customers on the checkout page.</div>
    </div>
    <div class="form-row">
        <label for="email">Contact email</label>
        <input id="email" type="email" name="email" value="{{ old('email', $merchant->email) }}" maxlength="150">
    </div>
</div>

<div class="form-row">
    <label for="website">Website</label>
    <input id="website" type="url" name="website" value="{{ old('website', $merchant->website) }}" placeholder="https://shop.example.com">
</div>

<div class="form-row">
    <label for="callback_url">Default callback URL</label>
    <input id="callback_url" type="url" name="callback_url" value="{{ old('callback_url', $merchant->callback_url) }}" placeholder="https://shop.example.com/payments/callback">
    <div class="hint">Signed status updates are POSTed here. A callback_url sent with a payment request overrides it.</div>
</div>

<fieldset class="fieldset">
    <legend>API IP allowlist</legend>
    <div class="alert alert--warning mt-0">Strict mode: a side with an <strong>empty</strong> list is blocked. Add at least one IP or CIDR per side, or that side's public API stays off (the admin/portal test Pay-In is server-side and keeps working). One entry per line — IPv4, IPv6 or CIDR (e.g. <span class="mono">203.0.113.0/24</span>).</div>
    <div class="grid grid-2">
        <div class="form-row">
            <label for="payin_ip_allowlist">Pay-In (deposit) IPs</label>
            <textarea id="payin_ip_allowlist" name="payin_ip_allowlist" rows="4" class="mono" placeholder="203.0.113.10&#10;203.0.113.0/24">{{ old('payin_ip_allowlist', $merchant->payin_ip_allowlist) }}</textarea>
            <div class="hint">May call <span class="mono">POST /payments</span> and <span class="mono">GET /payments/{id}</span>.</div>
        </div>
        <div class="form-row">
            <label for="payout_ip_allowlist">Pay-Out (withdrawal) IPs</label>
            <textarea id="payout_ip_allowlist" name="payout_ip_allowlist" rows="4" class="mono" placeholder="203.0.113.10">{{ old('payout_ip_allowlist', $merchant->payout_ip_allowlist) }}</textarea>
            <div class="hint">May call the <span class="mono">/payouts</span> endpoints. Balance works from either list.</div>
        </div>
    </div>
</fieldset>

<div class="grid grid-2">
    <div class="form-row">
        <label for="fee_percent">Fee % (optional)</label>
        <input id="fee_percent" type="number" name="fee_percent" value="{{ old('fee_percent', $merchant->fee_percent) }}" min="0" max="100" step="0.01">
    </div>
    <div class="form-row">
        <label for="fee_fixed">Fixed fee (optional)</label>
        <input id="fee_fixed" type="number" name="fee_fixed" value="{{ old('fee_fixed', $merchant->fee_fixed) }}" min="0" step="0.01">
    </div>
</div>
<p class="hint mt-0">Leave both fee fields empty to use the global fee settings.</p>

<div class="form-row">
    <label class="checkbox"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $merchant->is_active))> Active (API key accepted)</label>
</div>

<fieldset class="fieldset">
    <legend>Merchant portal login</legend>
    <p class="hint mt-0">The merchant signs in at <span class="mono">{{ route('merchant.login') }}</span> to see its Merchant ID and API credentials, check its integration and run a test Pay-In.</p>

    <div class="form-row">
        <label class="checkbox"><input type="checkbox" name="portal_enabled" value="1" @checked(old('portal_enabled', $merchant->portal_enabled))> Portal login enabled</label>
    </div>

    <div class="grid grid-2">
        <div class="form-row">
            <label for="username">Username</label>
            <input id="username" type="text" name="username" value="{{ old('username', $merchant->username) }}" maxlength="50" autocomplete="off" placeholder="e.g. demostore">
            <div class="hint">Letters, digits, . _ - (3-50 characters).</div>
        </div>
        <div class="form-row">
            <label for="portal_password">Password</label>
            <input id="portal_password" type="password" name="portal_password" autocomplete="new-password">
            <div class="hint">
                @if ($merchant->exists && $merchant->password)
                    Leave empty to keep the current password.
                @else
                    Leave empty to generate one (shown once).
                @endif
                Min 10 characters with letters and numbers.
            </div>
        </div>
    </div>
</fieldset>
