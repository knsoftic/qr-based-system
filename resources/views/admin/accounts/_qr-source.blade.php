{{-- How the account gets its QR data: image upload, pasted QR text, or a Raast P2P QR built from an IBAN. Params: $providers, $selectedProvider --}}
@php($method = old('method', 'image'))
<div class="qr-source" data-qr-source>
    <div class="form-row">
        <label>How do you want to add the QR?</label>
        <div class="segmented" role="radiogroup">
            <label @class(['active' => $method === 'image'])><input type="radio" name="method" value="image" @checked($method === 'image')> <x-icon name="qr" :size="16" /> Upload QR image</label>
            <label @class(['active' => $method === 'text'])><input type="radio" name="method" value="text" @checked($method === 'text')> <x-icon name="code" :size="16" /> Paste QR text</label>
            <label @class(['active' => $method === 'iban'])><input type="radio" name="method" value="iban" @checked($method === 'iban')> <x-icon name="wallet" :size="16" /> Add by IBAN</label>
        </div>
    </div>

    <div class="form-row" data-for="image text">
        <label for="provider">Provider</label>
        <select id="provider" name="provider">
            <option value="auto" @selected(old('provider', $selectedProvider ?? 'auto') === 'auto')>Auto-detect (recommended)</option>
            @foreach ($providers as $key => $name)
                <option value="{{ $key }}" @selected(old('provider', $selectedProvider ?? null) === $key)>{{ $name }}</option>
            @endforeach
        </select>
        <div class="hint">Auto-detect tries every supported format and keeps the one that verifies.</div>
    </div>

    <div class="form-row" data-for="image">
        <label for="qr_image">QR code image</label>
        <input id="qr_image" type="file" name="qr_image" accept="image/png,image/jpeg,image/gif,image/webp">
        <div class="hint">PNG, JPG, GIF or WEBP, up to 4 MB. If a big logo in the middle makes it unreadable, use "Paste QR text" or "Add by IBAN".</div>
    </div>

    <div class="form-row" data-for="text">
        <label for="qr_text">QR text</label>
        <textarea id="qr_text" name="qr_text" rows="3" class="mono" placeholder="000202010211020200…">{{ old('qr_text') }}</textarea>
        <div class="hint">Scan the QR with your phone camera or Google Lens, tap "copy text" and paste it here. It is verified exactly like an uploaded image (format + checksum).</div>
    </div>

    <div data-for="iban">
        <div class="form-row">
            <label for="iban">IBAN</label>
            <input id="iban" type="text" name="iban" value="{{ old('iban') }}" maxlength="40" class="mono" placeholder="PK36JCMA0000000000000000">
            <div class="hint">The 24-character IBAN of the JazzCash, easypaisa or bank account (in the app: My Account / IBAN). Check digits are verified.</div>
        </div>
        <div class="form-row">
            <label for="bank_name">Bank / wallet name (optional)</label>
            <input id="bank_name" type="text" name="bank_name" value="{{ old('bank_name') }}" maxlength="25" placeholder="e.g. JazzCash">
        </div>
        <p class="hint mt-0">A standard Raast P2P QR is built for this IBAN, the same layout banking apps issue. Every payment then gets its own dynamic QR with the amount and expiry.</p>
    </div>
</div>

@once
    <script>
        document.querySelectorAll('[data-qr-source]').forEach(function (box) {
            function sync() {
                var method = box.querySelector('input[name=method]:checked').value;
                box.querySelectorAll('.segmented label').forEach(function (l) {
                    l.classList.toggle('active', l.querySelector('input').checked);
                });
                box.querySelectorAll('[data-for]').forEach(function (el) {
                    var on = el.dataset.for.split(' ').indexOf(method) !== -1;
                    el.hidden = !on;
                    el.querySelectorAll('input, textarea, select').forEach(function (f) { f.disabled = !on; });
                });
                var required = { image: '#qr_image', text: '#qr_text', iban: '#iban' };
                Object.keys(required).forEach(function (m) {
                    var f = box.querySelector(required[m]);
                    if (f) { f.required = (m === method); }
                });
            }
            box.querySelectorAll('input[name=method]').forEach(function (r) { r.addEventListener('change', sync); });
            sync();
        });
    </script>
@endonce
