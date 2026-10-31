@extends('layouts.admin')

@section('title', 'Checkout Page')

@section('content')
    @php $c = fn ($k, $d = '') => old($k, $settings['checkout.'.$k] ?? $d); @endphp

    <div class="page-head">
        <div>
            <h1>Checkout page</h1>
            <p>Branding, Urdu text, Telegram, a custom button and the mobile-operator codes accepted at checkout.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.checkout.update') }}" enctype="multipart/form-data">
        @csrf

        <div class="card">
            <div class="card-head"><h2>Appearance</h2></div>
            <div class="card-body">
                <div class="grid grid-2">
                    <div class="form-row">
                        <label for="language">Language &amp; direction</label>
                        <select id="language" name="language">
                            <option value="ur" @selected($c('language', 'ur') === 'ur')>Urdu (right-to-left)</option>
                            <option value="en" @selected($c('language') === 'en')>English (left-to-right)</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="font">Font</label>
                        <select id="font" name="font">
                            <option value="nastaliq" @selected($c('font', 'nastaliq') === 'nastaliq')>Nastaliq (Noto Nastaliq Urdu / custom)</option>
                            <option value="system" @selected($c('font') === 'system')>System sans-serif</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-2">
                    <div class="form-row">
                        <label for="primary_color">Primary colour</label>
                        <input id="primary_color" type="color" name="primary_color" value="{{ $c('primary_color', '#6366f1') }}">
                    </div>
                    <div class="form-row">
                        <label for="accent_color">Accent colour</label>
                        <input id="accent_color" type="color" name="accent_color" value="{{ $c('accent_color', '#8b5cf6') }}">
                    </div>
                </div>

                <div class="grid grid-2">
                    <div class="form-row">
                        <label for="logo">Logo (PNG/JPG/WEBP, ≤1 MB)</label>
                        @if ($theme->logoUrl())
                            <div class="brand-preview"><img src="{{ $theme->logoUrl() }}" alt="logo"></div>
                            <label class="checkbox"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
                        @endif
                        <input id="logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp">
                    </div>
                    <div class="form-row">
                        <label for="font_file">Custom Urdu font (.ttf/.otf/.woff/.woff2, ≤5 MB)</label>
                        @if ($theme->customFontUrl())
                            <p class="hint mt-0">A custom font is uploaded. <label class="checkbox"><input type="checkbox" name="remove_font" value="1"> Remove it (use Noto Nastaliq Urdu)</label></p>
                        @else
                            <div class="hint mt-0">Leave empty to use Noto Nastaliq Urdu. Upload Jameel Noori Nastaleeq (or any Urdu font) to use your own.</div>
                        @endif
                        <input id="font_file" type="file" name="font_file" accept=".ttf,.otf,.woff,.woff2">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Text (shown to customers)</h2></div>
            <div class="card-body">
                <div class="form-row">
                    <label for="tagline">Tagline (under the amount)</label>
                    <input id="tagline" type="text" name="tagline" dir="auto" value="{{ $c('tagline') }}" maxlength="200">
                </div>
                <div class="grid grid-2">
                    <div class="form-row">
                        <label for="step1_title">Step 1 — title</label>
                        <input id="step1_title" type="text" name="step1_title" dir="auto" value="{{ $c('step1_title') }}" maxlength="300">
                    </div>
                    <div class="form-row">
                        <label for="step1_button">Step 1 — button label</label>
                        <input id="step1_button" type="text" name="step1_button" dir="auto" value="{{ $c('step1_button') }}" maxlength="100">
                    </div>
                </div>
                <div class="form-row">
                    <label for="step1_desc">Step 1 — description</label>
                    <textarea id="step1_desc" name="step1_desc" dir="auto" rows="3" maxlength="1000">{{ $c('step1_desc') }}</textarea>
                </div>
                <div class="form-row">
                    <label for="step2_hint">Step 2 — scan hint</label>
                    <input id="step2_hint" type="text" name="step2_hint" dir="auto" value="{{ $c('step2_hint') }}" maxlength="300">
                </div>
                <div class="form-row">
                    <label for="steps">Instruction steps (one per line)</label>
                    <textarea id="steps" name="steps" dir="auto" rows="3" maxlength="2000">{{ $c('steps') }}</textarea>
                </div>

                <div class="grid grid-2">
                    <div class="form-row">
                        <label for="paid_title">Paid — title</label>
                        <input id="paid_title" type="text" name="paid_title" dir="auto" value="{{ $c('paid_title') }}" maxlength="200">
                    </div>
                    <div class="form-row">
                        <label for="paid_text">Paid — text</label>
                        <input id="paid_text" type="text" name="paid_text" dir="auto" value="{{ $c('paid_text') }}" maxlength="1000">
                    </div>
                    <div class="form-row">
                        <label for="expired_title">Expired — title</label>
                        <input id="expired_title" type="text" name="expired_title" dir="auto" value="{{ $c('expired_title') }}" maxlength="200">
                    </div>
                    <div class="form-row">
                        <label for="expired_text">Expired — text</label>
                        <input id="expired_text" type="text" name="expired_text" dir="auto" value="{{ $c('expired_text') }}" maxlength="1000">
                    </div>
                    <div class="form-row">
                        <label for="failed_title">Failed — title</label>
                        <input id="failed_title" type="text" name="failed_title" dir="auto" value="{{ $c('failed_title') }}" maxlength="200">
                    </div>
                    <div class="form-row">
                        <label for="failed_text">Failed — text</label>
                        <input id="failed_text" type="text" name="failed_text" dir="auto" value="{{ $c('failed_text') }}" maxlength="1000">
                    </div>
                </div>

                <div class="grid grid-2">
                    <div class="form-row">
                        <label for="footer">Footer text</label>
                        <input id="footer" type="text" name="footer" dir="auto" value="{{ $c('footer') }}" maxlength="200">
                    </div>
                    <div class="form-row">
                        <label for="invalid_code_msg">Blocked-code error message</label>
                        <input id="invalid_code_msg" type="text" name="invalid_code_msg" dir="auto" value="{{ $c('invalid_code_msg') }}" maxlength="300">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Telegram &amp; custom button</h2></div>
            <div class="card-body">
                <div class="grid grid-2">
                    <div class="form-row">
                        <label for="telegram">Telegram (@username or t.me link)</label>
                        <input id="telegram" type="text" name="telegram" value="{{ $c('telegram') }}" maxlength="200" placeholder="@mystore">
                    </div>
                    <div class="form-row">
                        <label for="telegram_label">Telegram link label</label>
                        <input id="telegram_label" type="text" name="telegram_label" dir="auto" value="{{ $c('telegram_label') }}" maxlength="100">
                    </div>
                    <div class="form-row">
                        <label for="button_label">Button label</label>
                        <input id="button_label" type="text" name="button_label" dir="auto" value="{{ $c('button_label') }}" maxlength="100" placeholder="e.g. مدد / Help">
                    </div>
                    <div class="form-row">
                        <label for="button_url">Button link (https://)</label>
                        <input id="button_url" type="url" name="button_url" value="{{ $c('button_url') }}" maxlength="300" placeholder="https://...">
                    </div>
                </div>
                <p class="hint mt-0">The button shows on checkout only when both a label and a valid https link are set.</p>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Allowed mobile codes</h2></div>
            <div class="card-body">
                <p class="hint mt-0">The customer's pay-from number must start with an allowed code. Untick a company to block it; leave the whole list empty to allow every number. Codes are 4 digits (e.g. <span class="mono">0300</span>), comma separated.</p>
                <table class="codes-table">
                    <thead><tr><th>Company / wallet</th><th>Prefixes (0300, 0345 …)</th><th class="codes-allow">Allowed</th><th></th></tr></thead>
                    <tbody id="codes-rows"></tbody>
                </table>
                <button type="button" class="btn btn--sm" id="codes-add">+ Add company</button>
                <input type="hidden" name="codes_json" id="codes_json">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save checkout settings</button>
        </div>
    </form>

    <script id="codes-data" type="application/json">@json($settings['checkout.codes'] ?? [])</script>
    <script>
    (function () {
        var rows = document.getElementById('codes-rows');
        var hidden = document.getElementById('codes_json');
        var data;
        try { data = JSON.parse(document.getElementById('codes-data').textContent) || []; } catch (e) { data = []; }
        if (!data.length) { data = [{ label: '', prefixes: '', enabled: true }]; }

        function addRow(row) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><input type="text" class="c-label" maxlength="60" placeholder="Jazz / JazzCash"></td>' +
                '<td><input type="text" class="c-prefixes mono" placeholder="0300,0301,..."></td>' +
                '<td class="codes-allow"><input type="checkbox" class="c-enabled"></td>' +
                '<td><button type="button" class="btn btn--sm btn--danger c-remove">✕</button></td>';
            tr.querySelector('.c-label').value = row.label || '';
            tr.querySelector('.c-prefixes').value = row.prefixes || '';
            tr.querySelector('.c-enabled').checked = row.enabled !== false;
            tr.querySelector('.c-remove').addEventListener('click', function () { tr.remove(); sync(); });
            tr.addEventListener('input', sync);
            tr.addEventListener('change', sync);
            rows.appendChild(tr);
        }

        function sync() {
            var out = [];
            rows.querySelectorAll('tr').forEach(function (tr) {
                out.push({
                    label: tr.querySelector('.c-label').value.trim(),
                    prefixes: tr.querySelector('.c-prefixes').value.trim(),
                    enabled: tr.querySelector('.c-enabled').checked
                });
            });
            hidden.value = JSON.stringify(out);
        }

        data.forEach(addRow);
        sync();
        document.getElementById('codes-add').addEventListener('click', function () {
            addRow({ label: '', prefixes: '', enabled: true });
            sync();
        });
    })();
    </script>
@endsection
