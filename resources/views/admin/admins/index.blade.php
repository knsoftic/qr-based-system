@extends('layouts.admin')

@section('title', 'Admin Users')

@section('content')
    <div class="page-head">
        <div>
            <h1>Admin users</h1>
            <p>People who can sign in to this panel.</p>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Last sign-in</th><th>State</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td>
                                <strong>{{ $user->name }}</strong>
                                <div class="muted">{{ $user->email }}</div>
                            </td>
                            <td class="nowrap">
                                {{ $user->last_login_at?->diffForHumans() ?? 'Never' }}
                                @if ($user->last_login_ip)<div class="muted mono">{{ $user->last_login_ip }}</div>@endif
                            </td>
                            <td><span class="badge badge--{{ $user->is_active ? 'active' : 'inactive' }}">{{ $user->is_active ? 'Active' : 'Disabled' }}</span></td>
                            <td class="text-right">
                                @unless ($user->is(auth()->user()))
                                    <form method="POST" action="{{ route('admin.admins.toggle', $user) }}" class="inline-form">
                                        @csrf
                                        <button type="submit" class="btn btn--sm {{ $user->is_active ? 'btn--danger' : '' }}">{{ $user->is_active ? 'Disable' : 'Enable' }}</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Add admin</h2></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.admins.store') }}">
                    @csrf
                    <div class="form-row">
                        <label for="name">Name</label>
                        <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="100">
                    </div>
                    <div class="form-row">
                        <label for="email">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="150">
                    </div>
                    <div class="form-row">
                        <label for="password">Password</label>
                        <input id="password" type="password" name="password" required autocomplete="new-password">
                        <div class="hint">At least 10 characters with letters and numbers.</div>
                    </div>
                    <div class="form-row">
                        <label for="password_confirmation">Confirm password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn--primary">Create admin</button>
                </form>
            </div>
        </div>
    </div>
@endsection
