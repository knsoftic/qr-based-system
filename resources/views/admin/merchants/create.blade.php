@extends('layouts.admin')

@section('title', 'Add Merchant')

@section('content')
    <div class="page-head">
        <div>
            <h1>Add merchant</h1>
            <p>An API key and secret are generated automatically.</p>
        </div>
    </div>

    <div class="card" style="max-width: 720px">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.merchants.store') }}">
                @include('admin.merchants._form')
                <button type="submit" class="btn btn--primary">Create merchant</button>
                <a href="{{ route('admin.merchants.index') }}" class="btn">Cancel</a>
            </form>
        </div>
    </div>
@endsection
