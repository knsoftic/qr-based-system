@extends('layouts.admin')

@section('title', 'Staff & Roles')

@section('content')
    <div class="page-head">
        <div>
            <h1>Staff &amp; roles</h1>
            <p>Super admins, managers and agents who can sign in to this panel, and what each can do.</p>
        </div>
        <a href="{{ route('admin.admins.create') }}" class="btn btn--primary"><x-icon name="plus" :size="16" /> Add staff</a>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Supervisor</th>
                    <th>Access</th>
                    <th>Pay</th>
                    <th>Last sign-in</th>
                    <th>State</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td>
                            <strong>{{ $u->name }}</strong>
                            <div class="muted">{{ $u->email }}</div>
                        </td>
                        <td><span class="badge badge--{{ $u->isSuperAdmin() ? 'active' : ($u->isManager() ? 'warning' : 'inactive') }}">{{ $u->roleLabel() }}</span></td>
                        <td>{{ $u->parent?->name ?? '—' }}</td>
                        <td>
                            @if ($u->isSuperAdmin())
                                <span class="muted">Full access</span>
                            @else
                                {{ count($u->permissions ?? []) }} permission(s)
                            @endif
                        </td>
                        <td class="nowrap">
                            @php($bits = [])
                            @if ($u->salary > 0)@php($bits[] = 'PKR '.number_format($u->salary, 0).'/mo')@endif
                            @if ($u->payin_commission > 0)@php($bits[] = 'In '.rtrim(rtrim(number_format($u->payin_commission, 2), '0'), '.').'%')@endif
                            @if ($u->payout_commission > 0)@php($bits[] = 'Out '.rtrim(rtrim(number_format($u->payout_commission, 2), '0'), '.').'%')@endif
                            {{ $bits ? implode(' · ', $bits) : '—' }}
                        </td>
                        <td class="nowrap">
                            {{ $u->last_login_at?->diffForHumans() ?? 'Never' }}
                            @if ($u->last_login_ip)<div class="muted mono">{{ $u->last_login_ip }}</div>@endif
                        </td>
                        <td><span class="badge badge--{{ $u->is_active ? 'active' : 'inactive' }}">{{ $u->is_active ? 'Active' : 'Disabled' }}</span></td>
                        <td class="text-right nowrap">
                            <a href="{{ route('admin.admins.edit', $u) }}" class="btn btn--sm">Edit</a>
                            @unless ($u->is(auth()->user()))
                                <form method="POST" action="{{ route('admin.admins.toggle', $u) }}" class="inline-form">
                                    @csrf
                                    <button type="submit" class="btn btn--sm {{ $u->is_active ? 'btn--danger' : '' }}">{{ $u->is_active ? 'Disable' : 'Enable' }}</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
