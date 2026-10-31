@extends('layouts.admin')

@section('title', 'Edit '.$user->name)

@section('content')
    <div class="page-head">
        <div>
            <h1>Edit staff member</h1>
            <p>{{ $user->email }} · <span class="badge badge--active">{{ $user->roleLabel() }}</span></p>
        </div>
        <a href="{{ route('admin.admins.index') }}" class="btn">Back</a>
    </div>

    <div class="card" style="max-width: 820px">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.admins.update', $user) }}">
                @method('PUT')
                @include('admin.admins._form')
                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Save</button>
                    <a href="{{ route('admin.admins.index') }}" class="btn">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
