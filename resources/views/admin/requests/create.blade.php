@extends('layouts.admin')

@section('title', 'New account request')

@section('content')
    <div class="page-head">
        <div>
            <h1>New merchant account request</h1>
            <p>Submit the merchant's details and rates. A reviewer approves it to create the account.</p>
        </div>
        <a href="{{ route('admin.requests.index') }}" class="btn">Back</a>
    </div>

    <div class="card" style="max-width: 720px">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.requests.store') }}">
                @csrf
                <div class="form-row">
                    <label for="name">Merchant name</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="100" placeholder="Shop / business name">
                </div>
                <div class="grid grid-2">
                    <div class="form-row">
                        <label for="website">Website</label>
                        <input id="website" type="url" name="website" value="{{ old('website') }}" maxlength="255" placeholder="https://shop.example.com">
                    </div>
                    <div class="form-row">
                        <label for="callback_url">Callback URL (optional)</label>
                        <input id="callback_url" type="url" name="callback_url" value="{{ old('callback_url') }}" maxlength="255" placeholder="https://shop.example.com/callback">
                    </div>
                </div>

                <div class="grid grid-2">
                    <div class="form-row">
                        <label for="payin_rate">Pay-In rate (%)</label>
                        <input id="payin_rate" type="number" name="payin_rate" value="{{ old('payin_rate') }}" min="0" max="100" step="0.01" placeholder="0">
                    </div>
                    <div class="form-row">
                        <label for="payout_rate">Pay-Out rate (%)</label>
                        <input id="payout_rate" type="number" name="payout_rate" value="{{ old('payout_rate') }}" min="0" max="100" step="0.01" placeholder="0">
                    </div>
                </div>

                <fieldset class="fieldset">
                    <legend>Portal login (requested)</legend>
                    <div class="grid grid-2">
                        <div class="form-row">
                            <label for="username">Username</label>
                            <input id="username" type="text" name="username" value="{{ old('username') }}" required minlength="3" maxlength="50" autocomplete="off" placeholder="e.g. shopname">
                            <div class="hint">Letters, digits, . _ - (3–50). Must be free.</div>
                        </div>
                        <div class="form-row">
                            <label for="password">Password</label>
                            <input id="password" type="text" name="password" value="{{ old('password') }}" required minlength="10" autocomplete="off">
                            <div class="hint">At least 10 characters with letters and numbers. Stored encrypted until approved.</div>
                        </div>
                    </div>
                </fieldset>

                <div class="form-row">
                    <label for="note">Note to reviewer (optional)</label>
                    <input id="note" type="text" name="note" value="{{ old('note') }}" maxlength="500">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Submit request</button>
                    <a href="{{ route('admin.requests.index') }}" class="btn">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
