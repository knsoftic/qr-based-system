@extends('layouts.merchant')

@section('title', 'Test Pay-In')

@section('content')
    <div class="page-head">
        <div>
            <h1>Test Pay-In</h1>
            <p>Creates a payment exactly as your website would, through the live API with your own API key and secret, and shows the QR right away.</p>
        </div>
    </div>

    @include('partials.payin-test', ['action' => route('merchant.test.run'), 'test' => $test, 'adminLinks' => false])
@endsection
