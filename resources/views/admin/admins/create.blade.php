@extends('layouts.admin')

@section('title', 'Add staff')

@section('content')
    <div class="page-head">
        <div>
            <h1>Add staff member</h1>
            <p>Create a super admin, manager or agent and set what they can do.</p>
        </div>
        <a href="{{ route('admin.admins.index') }}" class="btn">Back</a>
    </div>

    <div class="card" style="max-width: 820px">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.admins.store') }}">
                @include('admin.admins._form')
                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Create</button>
                    <a href="{{ route('admin.admins.index') }}" class="btn">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
