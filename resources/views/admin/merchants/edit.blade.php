@extends('layouts.admin')

@section('title', 'Edit '.$merchant->name)

@section('content')
    <div class="page-head">
        <div><h1>Edit merchant</h1></div>
    </div>

    <div class="card" style="max-width: 720px">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.merchants.update', $merchant) }}">
                @method('PUT')
                @include('admin.merchants._form')
                <button type="submit" class="btn btn--primary">Save</button>
                <a href="{{ route('admin.merchants.show', $merchant) }}" class="btn">Cancel</a>
            </form>
        </div>
    </div>
@endsection
