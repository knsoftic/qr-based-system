@extends('layouts.merchant')

@section('title', 'Verify integration')

@section('content')
    <div class="page-head">
        <div>
            <h1>Verify integration</h1>
            <p>Check that your website uses the right credentials and signs requests the way the <a href="{{ route('docs') }}" target="_blank">API docs</a> describe.</p>
        </div>
    </div>

    <div class="card" id="credentials">
        <div class="card-head"><h2>1. Check API key and secret</h2></div>
        <div class="card-body">
            <p class="muted mt-0">Paste the values configured in your website. They are compared on the server and never shown back.</p>
            @if ($check = session('credential_check'))
                <div class="alert alert--{{ $check['key'] && $check['secret'] ? 'success' : 'danger' }}">
                    API key: <strong>{{ $check['key'] ? 'matches' : 'does not match' }}</strong> ·
                    API secret: <strong>{{ $check['secret'] ? 'matches' : 'does not match' }}</strong>
                    @unless ($check['secret'])
                        <div>If you rotated the secret, update your website with the new one (Credentials page).</div>
                    @endunless
                </div>
            @endif
            <form method="POST" action="{{ route('merchant.verify.credentials') }}">
                @csrf
                <div class="grid grid-2">
                    <div class="form-row">
                        <label for="api_key">API key</label>
                        <input id="api_key" type="text" name="api_key" required autocomplete="off" placeholder="pk_…">
                    </div>
                    <div class="form-row">
                        <label for="api_secret">API secret</label>
                        <input id="api_secret" type="password" name="api_secret" required autocomplete="off" placeholder="sk_…">
                    </div>
                </div>
                <button type="submit" class="btn btn--primary">Check credentials</button>
            </form>
        </div>
    </div>

    <div class="card" id="signature">
        <div class="card-head"><h2>2. Check a request signature</h2></div>
        <div class="card-body">
            <p class="muted mt-0">Copy the values your code used for one request. The server rebuilds the string to sign and the expected <span class="mono">X-Signature</span> with your secret.</p>

            @if ($sig = session('signature_check'))
                <div class="alert alert--{{ $sig['match'] ? 'success' : 'danger' }}">
                    Signature <strong>{{ $sig['match'] ? 'is correct' : 'does not match' }}</strong>.
                    @unless ($sig['fresh'])
                        Timestamp is {{ abs($sig['age']) }} seconds {{ $sig['age'] >= 0 ? 'old' : 'in the future' }}; the API only accepts ±{{ config('gateway.signature_tolerance') }} seconds.
                    @endunless
                </div>
                <div class="grid grid-2">
                    <div>
                        <h3>String to sign (⏎ = newline)</h3>
                        <pre class="json">{{ str_replace("\n", "⏎\n", $sig['canonical']) }}</pre>
                    </div>
                    <div>
                        <h3>Expected X-Signature</h3>
                        <pre class="json">{{ $sig['expected'] }}</pre>
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('merchant.verify.signature') }}" style="margin-top: 1rem">
                @csrf
                <div class="grid grid-3">
                    <div class="form-row">
                        <label for="sig_timestamp">X-Timestamp</label>
                        <input id="sig_timestamp" type="text" name="sig_timestamp" value="{{ old('sig_timestamp', time()) }}" required>
                    </div>
                    <div class="form-row">
                        <label for="sig_method">Method</label>
                        <select id="sig_method" name="sig_method">
                            @foreach (['POST', 'GET'] as $method)
                                <option @selected(old('sig_method', 'POST') === $method)>{{ $method }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="sig_uri">Request URI</label>
                        <input id="sig_uri" type="text" name="sig_uri" value="{{ old('sig_uri', parse_url(url('/api/v1/payments'), PHP_URL_PATH)) }}" required>
                    </div>
                </div>
                <div class="form-row">
                    <label for="sig_body">Raw request body (empty for GET)</label>
                    <textarea id="sig_body" name="sig_body" rows="5" class="mono" placeholder='{"amount":1500,"order_id":"ORDER-1"}'>{{ old('sig_body') }}</textarea>
                </div>
                <div class="form-row">
                    <label for="sig_signature">X-Signature you sent</label>
                    <input id="sig_signature" type="text" name="sig_signature" value="{{ old('sig_signature') }}" required class="mono">
                </div>
                <button type="submit" class="btn btn--primary">Check signature</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Common mistakes</h2></div>
        <div class="card-body">
            <ul class="mb-0">
                <li>Sign the <strong>exact</strong> body bytes you send (encode JSON once, sign that string, send that string).</li>
                <li>The URI is the path plus query string, e.g. <span class="mono">{{ parse_url(url('/api/v1/payments'), PHP_URL_PATH) }}</span>, not the full URL.</li>
                <li>Method in capitals, timestamp in <strong>seconds</strong>, signature in lowercase hex.</li>
                <li>Every <span class="mono">POST</span> needs an <span class="mono">Idempotency-Key</span>; reuse it only to retry the same order.</li>
                <li>A signature can be used once; build a new one (new timestamp) for every request.</li>
            </ul>
        </div>
    </div>
@endsection
