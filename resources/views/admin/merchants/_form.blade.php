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
