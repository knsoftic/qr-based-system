<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merchant API · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=5">
</head>
<body>
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
    <h2 style="border-top: 0; margin-top: .5rem">1. How it works</h2>
    <ol>
        <li>Your server creates a payment: <code>POST /payments</code> with the amount and your order ID.</li>
        <li>You receive <code>payment_id</code>, <code>transaction_id</code> and <code>checkout_url</code>. Redirect the customer to the checkout URL.</li>
        <li>The customer scans a dynamic QR with the exact amount. The QR expires after <strong>{{ $expiryMinutes }} minutes</strong>.</li>
        <li>When the payment is verified (or expires) we POST a signed callback to your server and the customer is sent back to your <code>return_url</code>.</li>
    </ol>
    <p>Base URL: <code>{{ $baseUrl }}</code> · Currency: <code>{{ $currency }}</code> · All bodies are JSON.</p>

    <h2>2. Authentication &amp; signing</h2>
    <p>Sign in to the <a href="{{ route('merchant.login') }}">merchant portal</a> with the login your gateway admin gave you. It shows your <strong>Merchant ID</strong>, <strong>API key</strong> and <strong>API secret</strong>, checks your request signatures, and runs a test Pay-In with a live QR. Never put the secret in browser or mobile code.</p>
    <table>
        <thead><tr><th>Header</th><th>Value</th></tr></thead>
        <tbody>
        <tr><td><code>X-Api-Key</code></td><td>Your API key (<code>pk_…</code>)</td></tr>
        <tr><td><code>X-Timestamp</code></td><td>Current Unix time in seconds (must be within {{ config('gateway.signature_tolerance') }} s of server time)</td></tr>
        <tr><td><code>X-Signature</code></td><td>Hex HMAC-SHA256 of the string to sign, keyed with your API secret</td></tr>
        <tr><td><code>Idempotency-Key</code></td><td>Required on <code>POST</code>. A unique value per order attempt (8–128 chars). Retrying with the same key returns the same payment instead of creating a duplicate.</td></tr>
        </tbody>
    </table>
    <p>String to sign (four parts joined with a newline <code>\n</code>):</p>
    <pre>timestamp + "\n" + HTTP_METHOD + "\n" + REQUEST_URI + "\n" + RAW_BODY</pre>
    <p><code>REQUEST_URI</code> is the path plus query string exactly as requested, e.g. <code>{{ parse_url($baseUrl, PHP_URL_PATH) }}/payments</code>. For <code>GET</code> the body is an empty string. A signature cannot be reused; the only exception is an exact retry of a <code>POST</code> with the same <code>Idempotency-Key</code>, which safely returns the original payment.</p>

    <h2>3. Create a payment</h2>
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
    <p>Only <code>amount</code> and <code>order_id</code> are required. Response <code>201 Created</code> (or <code>200</code> with <code>Idempotent-Replayed: true</code> for a retry):</p>
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

    <h2>4. Get payment status</h2>
    <pre>GET {{ $baseUrl }}/payments/{payment_id}</pre>
    <p>Returns the same object. Statuses: <code>pending</code>, <code>paid</code>, <code>expired</code>, <code>failed</code>, <code>cancelled</code>.
        Use this as a fallback if a callback is missed.</p>

    <h2>5. Callbacks</h2>
    <p>On every status change we <code>POST</code> to your <code>callback_url</code>:</p>
    <pre>{
  "event": "payment.paid",
  "data": { …same payment object… },
  "sent_at": "2026-10-28T15:32:10+00:00"
}</pre>
    <p>Verify headers <code>X-Timestamp</code> and <code>X-Signature</code> = hex HMAC-SHA256 of <code>timestamp + "." + raw_body</code> with your API secret.
        Respond with HTTP 2xx. Failed deliveries are retried with exponential backoff. Always treat callbacks idempotently.</p>

    <h2>6. Errors</h2>
    <pre>{ "error": { "code": "validation_error", "message": "...", "fields": { "amount": ["..."] } } }</pre>
    <table>
        <thead><tr><th>HTTP</th><th>code</th><th>Meaning</th></tr></thead>
        <tbody>
        <tr><td>400</td><td><code>idempotency_key_required</code></td><td>Missing or invalid Idempotency-Key header</td></tr>
        <tr><td>401</td><td><code>unauthenticated</code>, <code>invalid_signature</code>, <code>timestamp_out_of_range</code>, <code>replayed_request</code></td><td>Authentication failed</td></tr>
        <tr><td>404</td><td><code>not_found</code></td><td>Unknown payment</td></tr>
        <tr><td>409</td><td><code>idempotency_conflict</code></td><td>Same Idempotency-Key used with a different body</td></tr>
        <tr><td>422</td><td><code>validation_error</code></td><td>Invalid input (see <code>fields</code>)</td></tr>
        <tr><td>429</td><td><code>rate_limited</code></td><td>Too many requests; retry later</td></tr>
        <tr><td>503</td><td><code>no_account_available</code>, <code>payin_disabled</code></td><td>Temporarily unable to accept payments</td></tr>
        </tbody>
    </table>

    <h2>7. PHP example</h2>
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

// callback.php: verify before trusting
$raw = file_get_contents('php://input');
$expected = hash_hmac('sha256', $_SERVER['HTTP_X_TIMESTAMP'] . '.' . $raw, $apiSecret);
if (!hash_equals($expected, $_SERVER['HTTP_X_SIGNATURE'] ?? '')) { http_response_code(401); exit; }
$event = json_decode($raw, true);
// mark order paid when $event['data']['status'] === 'paid' (check amount + order_id)</pre>

    <h2>8. Payment verification API (gateway operators)</h2>
    <p>A trusted verifier can confirm a received payment. Enable it in <em>Admin → Settings → Verification</em>.</p>
    <pre>POST {{ $baseUrl }}/verifications
X-Verifier-Key: vk_…

{ "transaction_id": "TXN261028153045123456", "reference": "BANK-REF-123" }
// or match by exact amount (optionally per account):
{ "amount": 1500.00, "account_id": 3, "reference": "BANK-REF-123" }</pre>

    <p class="muted" style="margin-top: 2rem">Security: secrets are stored encrypted, every request is signed and rate limited, and payment requests are idempotent.</p>
</div>
</body>
</html>
