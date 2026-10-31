@extends('layouts.admin')

@section('title', 'Add Payment Account')

@section('content')
    <div class="page-head">
        <div>
            <h1>Add payment account</h1>
            <p>Upload the authorized QR (read automatically), paste its text, or add a Raast account by IBAN.</p>
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
                        <label for="channel">Channel (payment method / company)</label>
                        <input id="channel" type="text" name="channel" value="{{ old('channel') }}" maxlength="50" list="channel-list" placeholder="e.g. easypaisa, JazzCash, Raast, Bank">
                        <div class="hint">Group accounts by method so you can put a whole method into maintenance at once. Optional.</div>
                        <datalist id="channel-list">
                            @foreach ($channels as $ch)<option value="{{ $ch }}">@endforeach
                            <option value="easypaisa"><option value="JazzCash"><option value="Raast"><option value="Bank">
                        </datalist>
                    </div>

                    @include('admin.accounts._qr-source', ['providers' => $providers, 'selectedProvider' => 'auto'])

                    <div class="grid grid-2">
                        <div class="form-row">
                            <label for="priority">Priority</label>
                            <input id="priority" type="number" name="priority" value="{{ old('priority', 100) }}" min="1" max="1000">
                            <div class="hint">Lower is preferred (priority routing).</div>
                        </div>
                        <div class="form-row">
                            <label for="daily_limit">Daily limit (PKR, optional)</label>
                            <input id="daily_limit" type="number" name="daily_limit" value="{{ old('daily_limit') }}" min="0" step="0.01" placeholder="No limit">
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="monthly_limit">Monthly limit (PKR, optional)</label>
                        <input id="monthly_limit" type="number" name="monthly_limit" value="{{ old('monthly_limit') }}" min="0" step="0.01" placeholder="No limit">
                        <div class="hint">When a day's or month's received total reaches the limit, the account goes into maintenance and stops getting new payments. The monthly counter resets on the 1st.</div>
                    </div>

                    <div class="form-row">
                        <label class="checkbox"><input type="checkbox" name="activate" value="1" @checked(old('activate', true))> Activate automatically if the QR is verified</label>
                    </div>

                    <button type="submit" class="btn btn--primary">Add &amp; verify account</button>
                    <a href="{{ route('admin.accounts.index') }}" class="btn">Cancel</a>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>What happens next</h2></div>
            <div class="card-body">
                <ol style="padding-left: 1.1rem; margin: 0">
                    <li>The image is decoded on the server (or your pasted text / IBAN is used) and the QR payload is extracted.</li>
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
