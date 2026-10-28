@extends('layouts.admin')

@section('title', 'Edit '.$account->name)

@section('content')
    <div class="page-head">
        <div>
            <h1>Edit account</h1>
            <p>To change the QR or provider, use “Replace QR” on the account page.</p>
        </div>
    </div>

    <div class="card" style="max-width: 560px">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.accounts.update', $account) }}">
                @csrf
                @method('PUT')
                <div class="form-row">
                    <label for="name">Account name</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $account->name) }}" required maxlength="100">
                </div>
                <div class="form-row">
                    <label for="priority">Priority</label>
                    <input id="priority" type="number" name="priority" value="{{ old('priority', $account->priority) }}" min="1" max="1000" required>
                    <div class="hint">Lower is preferred when routing strategy is “Priority”.</div>
                </div>
                <div class="form-row">
                    <label for="daily_limit">Daily limit</label>
                    <input id="daily_limit" type="number" name="daily_limit" value="{{ old('daily_limit', $account->daily_limit) }}" min="0" step="0.01">
                    <div class="hint">Leave empty for no limit. Pending + paid amounts count toward the limit.</div>
                </div>
                <button type="submit" class="btn btn--primary">Save</button>
                <a href="{{ route('admin.accounts.show', $account) }}" class="btn">Cancel</a>
            </form>
        </div>
    </div>
@endsection
