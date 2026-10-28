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
@endsection
