@extends('layouts.admin')

@section('title', 'Add Payment Account')

@section('content')
    <div class="page-head">
        <div>
            <h1>Add payment account</h1>
            <p>Upload the authorized QR. The payload is read automatically; nothing has to be typed in.</p>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.accounts.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="form-row">
                        <label for="name">Account name</label>
                        <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="100" placeholder="e.g. Main shop account">
                        <div class="hint">Internal label, never shown to customers.</div>
                    </div>

                    <div class="form-row">
                        <label for="provider">Provider</label>
                        <select id="provider" name="provider" required>
                            <option value="auto" @selected(old('provider', 'auto') === 'auto')>Auto-detect (recommended)</option>
                            @foreach ($providers as $key => $name)
                                <option value="{{ $key }}" @selected(old('provider') === $key)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <div class="hint">Auto-detect tries every supported format and keeps the one that verifies.</div>
                    </div>

                    <div class="form-row">
                        <label for="qr_image">Authorized QR code image</label>
                        <input id="qr_image" type="file" name="qr_image" accept="image/png,image/jpeg,image/gif,image/webp" required>
                        <div class="hint">PNG, JPG, GIF or WEBP, up to 4 MB. Use a clear, uncropped image.</div>
                    </div>

                    <div class="grid grid-2">
                        <div class="form-row">
                            <label for="priority">Priority</label>
                            <input id="priority" type="number" name="priority" value="{{ old('priority', 100) }}" min="1" max="1000">
                            <div class="hint">Lower is preferred (priority routing).</div>
                        </div>
                        <div class="form-row">
                            <label for="daily_limit">Daily limit (optional)</label>
                            <input id="daily_limit" type="number" name="daily_limit" value="{{ old('daily_limit') }}" min="0" step="0.01">
                        </div>
                    </div>

                    <div class="form-row">
                        <label class="checkbox"><input type="checkbox" name="activate" value="1" @checked(old('activate', true))> Activate automatically if the QR is verified</label>
                    </div>

                    <button type="submit" class="btn btn--primary">Upload &amp; decode QR</button>
                    <a href="{{ route('admin.accounts.index') }}" class="btn">Cancel</a>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>What happens on upload</h2></div>
            <div class="card-body">
                <ol style="padding-left: 1.1rem; margin: 0">
                    <li>The image is decoded on the server and the QR payload is extracted.</li>
                    <li>The selected provider adapter identifies the format and verifies the structure and checksum (CRC).</li>
                    <li>Only if verification passes is a payload template saved, encrypted at rest.</li>
                    <li>For each payment, only the fields the specification allows are changed: amount, reference (transaction ID), initiation method and checksum.</li>
                </ol>
                <p class="muted" style="margin-top: .75rem">
                    QR codes that cannot be identified and verified are marked <em>Unsupported</em> and can never be activated.
                    The system never edits an unknown QR blindly.
                </p>
            </div>
        </div>
    </div>
@endsection
