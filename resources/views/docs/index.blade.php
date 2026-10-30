<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merchant API · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=8">
</head>
<body>
@php
    // Everything variable on this page comes from code / config / settings, so
    // the docs track the implementation. Section numbers are a CSS counter, so
    // adding or removing a section never means renumbering by hand.
    $methodList = implode(', ', array_keys($payoutMethods));
    $money = fn ($v) => number_format((float) $v, 0);
@endphp
<header class="docs-top">
    <div class="brand"><span class="brand-mark">QR</span> {{ config('app.name') }}</div>
    <a href="{{ route('merchant.login') }}" class="btn btn--primary btn--sm"><x-icon name="store" :size="15" /> Merchant login</a>
</header>

<section class="docs-hero">
    <div>
        <h1>Merchant API integration</h1>
        <p>Accept Raast QR payments on your website with two API calls and one callback.</p>
    </div>
</section>

<div class="docs">
    <h2 style="border-top: 0; margin-top: .5rem">How it works</h2>
    <ol>
        <li>Your server creates a payment: <code>POST /payments</code> with the amount and your order ID.</li>
        <li>You receive <code>payment_id</code>, <code>transaction_id</code> and <code>checkout_url</code>. Redirect the customer to the checkout URL.</li>
        <li>The customer scans a dynamic QR with the exact amount. The QR expires after <strong>{{ $expiryMinutes }} minutes</strong>.</li>
        <li>When the payment is verified (or expires) we POST a signed callback to your server and the customer is sent back to your <code>return_url</code>.</li>
    </ol>
    <p>Base URL: <code>{{ $baseUrl }}</code> · Currency: <code>{{ $currency }}</code> · All bodies are JSON.</p>

    <h2>Authentication &amp; signing</h2>
    <p>Sign in to the <a href="{{ route('merchant.login') }}">merchant portal</a> with the login your gateway admin gave you. It shows your <strong>Merchant ID</strong>, <strong>API key</strong> and <strong>API secret</strong>, checks your request signatures, and runs a test Pay-In with a live QR. Never put the secret in browser or mobile code.</p>
    <table>
        <thead><tr><th>Header</th><th>Value</th></tr></thead>
        <tbody>
        <tr><td><code>X-Api-Key</code></td><td>Your API key (<code>pk_…</code>)</td></tr>
        <tr><td><code>X-Timestamp</code></td><td>Current Unix time in seconds (must be within {{ $signatureTolerance }} s of server time)</td></tr>
        <tr><td><code>X-Signature</code></td><td>Hex HMAC-SHA256 of the string to sign, keyed with your API secret</td></tr>
        <tr><td><code>Idempotency-Key</code></td><td>Required on <code>POST</code>. A unique value per order attempt (8–128 chars). Retrying with the same key returns the same payment instead of creating a duplicate.</td></tr>
        </tbody>
    </table>
    <p>String to sign (four parts joined with a newline <code>\n</code>):</p>
    <pre>timestamp + "\n" + HTTP_METHOD + "\n" + REQUEST_URI + "\n" + RAW_BODY</pre>
    <p><code>REQUEST_URI</code> is the path plus query string exactly as requested, e.g. <code>{{ parse_url($baseUrl, PHP_URL_PATH) }}/payments</code>. For <code>GET</code> the body is an empty string. A signature cannot be reused; the only exception is an exact retry of a <code>POST</code> with the same <code>Idempotency-Key</code>, which safely returns the original payment.</p>
    <p>Requests are rate limited to about <strong>{{ $rateLimit }} per minute</strong>. Always use <code>https://</code>.</p>

    <h2 id="ip-allowlist">IP allowlist (required)</h2>
    <p>Your API is locked to the IP addresses your gateway admin sets for you, kept <strong>separately for pay-in (deposit) and pay-out (withdrawal)</strong>. A request from any other address is refused:</p>
    <pre>HTTP 403
{ "error": { "code": "ip_not_allowed", "message": "This IP address is not allowed for this operation." } }</pre>
    <table>
        <thead><tr><th>Allowlist</th><th>Applies to</th></tr></thead>
        <tbody>
        <tr><td>Pay-In</td><td><code>POST /payments</code>, <code>GET /payments/{id}</code></td></tr>
        <tr><td>Pay-Out</td><td><code>POST /payouts</code>, <code>GET /payouts</code>, <code>GET /payouts/{ref}</code></td></tr>
        <tr><td>Either</td><td><code>GET /balance</code> (allowed from a pay-in <em>or</em> pay-out IP)</td></tr>
        </tbody>
    </table>
    <p><strong>Strict:</strong> if a side's list is empty, that side is blocked until the admin adds an IP. Each entry is a single IP or a CIDR range (IPv4 or IPv6), for example <code>203.0.113.10</code> or <code>203.0.113.0/24</code>. Your current IP and configured allowlists are shown on your <a href="{{ route('merchant.login') }}">portal dashboard</a> — send the admin the server IP you call from.</p>

    <h2>Create a payment</h2>
    <pre>POST {{ $baseUrl }}/payments

{
  "amount": 1500.00,
  "order_id": "ORDER-10045",
  "description": "2 x T-shirt",
  "customer": { "name": "Ali Khan", "email": "ali@example.com", "phone": "+923001234567" },
  "callback_url": "https://shop.example.com/payments/callback",
  "return_url": "https://shop.example.com/orders/10045",
  "metadata": { "cart_id": "88123" }
}</pre>
    <p>Only <code>amount</code> and <code>order_id</code> are required. Amount must be between <code>{{ $money($payinMin) }}</code> and <code>{{ $money($payinMax) }}</code> {{ $currency }}. Response <code>201 Created</code> (or <code>200</code> with <code>Idempotent-Replayed: true</code> for a retry):</p>
    <pre>{
  "data": {
    "payment_id": "pay_01jbd3m4w2k8x9t7q5v6n3r2p1",
    "transaction_id": "TXN261028153045123456",
    "order_id": "ORDER-10045",
    "amount": "1500.00",
    "currency": "{{ $currency }}",
    "fee": "0.00",
    "net_amount": "1500.00",
    "status": "pending",
    "checkout_url": "{{ url('/checkout') }}/pay_01jbd3m4w2k8x9t7q5v6n3r2p1",
    "expires_at": "2026-10-28T15:35:45+00:00",
    "paid_at": null,
    "created_at": "2026-10-28T15:30:45+00:00"
  }
}</pre>

    <h2>Get payment status</h2>
    <pre>GET {{ $baseUrl }}/payments/{payment_id}</pre>
    <p>Returns the same object. Statuses: @foreach ($paymentStatuses as $s)<code>{{ $s }}</code>@if (! $loop->last), @endif @endforeach.
        Use this as a fallback if a callback is missed.</p>

    <h2 id="balance">Balance</h2>
    <p>Your current balance, all amounts in <code>{{ $currency }}</code>:</p>
    <pre>GET {{ $baseUrl }}/balance</pre>
    <pre>{
  "data": {
    "available": "4250.00",
    "frozen": "1000.00",
    "paid_out": "7500.00",
    "earned": "12750.00",
    "currency": "{{ $currency }}"
  }
}</pre>
    <table>
        <thead><tr><th>Field</th><th>Meaning</th></tr></thead>
        <tbody>
        <tr><td><code>earned</code></td><td>Net of every <code>paid</code> payment (amount minus fee)</td></tr>
        <tr><td><code>frozen</code></td><td>Held for withdrawals you have requested that are still pending</td></tr>
        <tr><td><code>paid_out</code></td><td>Withdrawals already sent to you</td></tr>
        <tr><td><code>available</code></td><td><code>earned − frozen − paid_out</code> — what you can withdraw now</td></tr>
        </tbody>
    </table>

    <h2 id="payouts">Withdrawals (Pay-Out)</h2>
    <p>Request a withdrawal of your available balance. Allowed methods: <code>{{ $methodList }}</code>. The amount (between <code>{{ $money($payoutMin) }}</code> and <code>{{ $money($payoutMax) }}</code> {{ $currency }}) is frozen immediately; an operator sends the money manually and then marks it paid.</p>
    <pre>POST {{ $baseUrl }}/payouts

{
  "method": "{{ array_key_first($payoutMethods) }}",            // one of: {{ $methodList }}
  "account_number": "03001234567",  // the mobile wallet number
  "account_name": "Ali Khan",       // optional
  "amount": 1000.00,
  "note": "weekly settlement"       // optional
}</pre>
    <p>Response <code>201 Created</code>:</p>
    <pre>{
  "data": {
    "payout_ref": "PO2610301A2B3C",
    "method": "{{ array_key_first($payoutMethods) }}",
    "account_number": "03001234567",
    "account_name": "Ali Khan",
    "amount": "1000.00",
    "currency": "{{ $currency }}",
    "status": "pending",
    "note": "weekly settlement",
    "reference": null,
    "created_at": "2026-10-30T09:15:00+00:00",
    "processed_at": null
  }
}</pre>
    <p>Statuses: @foreach ($payoutStatuses as $s)<code>{{ $s }}</code>@if (! $loop->last), @endif @endforeach — <code>pending</code> (requested, amount frozen), <code>paid</code> (money sent — moves from frozen to paid out), <code>rejected</code> (amount returns to your available balance). List or fetch:</p>
    <pre>GET {{ $baseUrl }}/payouts
GET {{ $baseUrl }}/payouts/{payout_ref}</pre>
    <p>A <code>422</code> with code <code>insufficient_balance</code> means the amount is more than your available balance. Withdrawals can also be requested from the <a href="{{ route('merchant.login') }}">merchant portal</a>.</p>

    <h2>Callbacks</h2>
    <p>On every status change we <code>POST</code> to your <code>callback_url</code>:</p>
    <pre>{
  "event": "payment.paid",
  "data": { …same payment object… },
  "sent_at": "2026-10-28T15:32:10+00:00"
}</pre>
    <p>Verify headers <code>X-Timestamp</code> and <code>X-Signature</code> = hex HMAC-SHA256 of <code>timestamp + "." + raw_body</code> with your API secret.
        Respond with HTTP 2xx. Failed deliveries are retried with exponential backoff. Always treat callbacks idempotently.</p>

    <h2>Errors</h2>
    <pre>{ "error": { "code": "validation_error", "message": "...", "fields": { "amount": ["..."] } } }</pre>
    <table>
        <thead><tr><th>HTTP</th><th>code</th><th>Meaning</th></tr></thead>
        <tbody>
        @foreach ($errors as $code => $info)
            <tr><td>{{ $info['status'] }}</td><td><code>{{ $code }}</code></td><td>{{ $info['meaning'] }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <h2>PHP example</h2>
    <pre>&lt;?php
$apiKey    = 'pk_your_key';
$apiSecret = 'sk_your_secret';
$baseUrl   = '{{ $baseUrl }}';

function gatewayRequest(string $method, string $path, ?array $payload = null): array
{
    global $apiKey, $apiSecret, $baseUrl;

    $url  = $baseUrl . $path;
    $body = $payload === null ? '' : json_encode($payload);
    $ts   = (string) time();
    $uri  = parse_url($url, PHP_URL_PATH) . (parse_url($url, PHP_URL_QUERY) ? '?' . parse_url($url, PHP_URL_QUERY) : '');
    $sig  = hash_hmac('sha256', $ts . "\n" . $method . "\n" . $uri . "\n" . $body, $apiSecret);

    $headers = ["Content-Type: application/json", "Accept: application/json",
                "X-Api-Key: $apiKey", "X-Timestamp: $ts", "X-Signature: $sig"];
    if ($method === 'POST') {
        $headers[] = 'Idempotency-Key: order-' . $payload['order_id'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                            CURLOPT_POSTFIELDS => $body ?: null, CURLOPT_RETURNTRANSFER => true]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $response;
}

$payment = gatewayRequest('POST', '/payments', ['amount' => 1500, 'order_id' => 'ORDER-10045',
    'return_url' => 'https://shop.example.com/orders/10045']);

header('Location: ' . $payment['data']['checkout_url']);

// Query your balance / request a withdrawal:
$balance = gatewayRequest('GET', '/balance');
// $payout = gatewayRequest('POST', '/payouts', ['method' => '{{ array_key_first($payoutMethods) }}',
//     'account_number' => '03001234567', 'amount' => 1000]);

// callback.php: verify before trusting
$raw = file_get_contents('php://input');
$expected = hash_hmac('sha256', $_SERVER['HTTP_X_TIMESTAMP'] . '.' . $raw, $apiSecret);
if (!hash_equals($expected, $_SERVER['HTTP_X_SIGNATURE'] ?? '')) { http_response_code(401); exit; }
$event = json_decode($raw, true);
// mark order paid when $event['data']['status'] === 'paid' (check amount + order_id)</pre>

    <h2>Payment verification API (gateway operators)</h2>
    <p>A trusted verifier can confirm a received payment. Enable it in <em>Admin → Settings → Verification</em>.</p>
    <pre>POST {{ $baseUrl }}/verifications
X-Verifier-Key: vk_…

{ "transaction_id": "TXN261028153045123456", "reference": "BANK-REF-123" }
// or match by exact amount (optionally per account):
{ "amount": 1500.00, "account_id": 3, "reference": "BANK-REF-123" }</pre>
    <table>
        <thead><tr><th>HTTP</th><th>code</th><th>Meaning</th></tr></thead>
        <tbody>
        @foreach ($verifierErrors as $code => $info)
            <tr><td>{{ $info['status'] }}</td><td><code>{{ $code }}</code></td><td>{{ $info['meaning'] }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <p class="muted" style="margin-top: 2rem">Security: requests are signed (HMAC-SHA256) and single-use, timestamped within a {{ $signatureTolerance }} s window, IP-allowlisted per side, rate limited and idempotent on POST; secrets are stored encrypted and never returned.</p>
</div>
</body>
</html>
