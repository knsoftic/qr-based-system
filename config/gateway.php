<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pay-In defaults
    |--------------------------------------------------------------------------
    |
    | These values seed the admin "Pay-In Settings" screen. Once an admin
    | saves the settings, the database values take precedence.
    |
    */

    'qr_expiry_seconds' => (int) env('GATEWAY_QR_EXPIRY_SECONDS', 300),

    'min_amount' => (float) env('GATEWAY_MIN_AMOUNT', 1),

    'max_amount' => (float) env('GATEWAY_MAX_AMOUNT', 500000),

    'currency' => env('GATEWAY_CURRENCY', 'PKR'),

    // round_robin | priority | random
    'routing_strategy' => env('GATEWAY_ROUTING_STRATEGY', 'round_robin'),

    /*
    |--------------------------------------------------------------------------
    | Merchant API security
    |--------------------------------------------------------------------------
    */

    // Maximum clock skew (seconds) accepted for the X-Timestamp header.
    'signature_tolerance' => (int) env('GATEWAY_SIGNATURE_TOLERANCE', 300),

    // Requests per minute per merchant API key.
    'api_rate_limit' => (int) env('GATEWAY_API_RATE_LIMIT', 60),

    // Allow callback URLs that resolve to private / loopback addresses.
    // Keep this false in production to prevent SSRF.
    'allow_private_callbacks' => (bool) env('GATEWAY_ALLOW_PRIVATE_CALLBACKS', env('APP_ENV') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Callbacks
    |--------------------------------------------------------------------------
    */

    'callback_timeout' => 10,

    'callback_max_attempts' => 5,

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    |
    | A payment that expired can still be confirmed by the verification API
    | within this grace window (a customer may finish paying at 4:59 and the
    | bank notification may arrive a little later).
    |
    */

    'late_verification_grace_seconds' => 900,

    /*
    |--------------------------------------------------------------------------
    | Deployment
    |--------------------------------------------------------------------------
    |
    | trusted_proxies: set to "*" (or a comma separated IP list) only when the
    | site sits behind Cloudflare / a load balancer, so client IPs and HTTPS
    | are detected correctly.
    |
    | admin: first admin created by `php artisan db:seed`. Prefer
    | `php artisan gateway:create-admin` in production and keep
    | ADMIN_PASSWORD empty.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'admin' => [
        'name' => env('ADMIN_NAME', 'Super Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | QR providers
    |--------------------------------------------------------------------------
    |
    | Every uploaded QR is processed by exactly one provider adapter. An
    | adapter only modifies the fields its specification allows and rejects
    | payloads it cannot positively identify and verify (format + checksum).
    |
    | "rules" narrow a generic adapter to a specific scheme. Only add rules
    | that come from the provider's official specification.
    |
    | Order matters for "Auto-detect": the first adapter that verifies the
    | QR wins, so the most specific ones come first.
    |
    */

    'providers' => [

        'raast_p2p' => [
            'name' => 'Raast P2P QR (IBAN: easypaisa, JazzCash, banks)',
            'adapter' => App\Payments\Providers\RaastP2pProvider::class,
            'rules' => [
                // Tag 07 expiry is written in this time zone.
                'timezone' => 'Asia/Karachi',
                // Also write the transaction ID into tag 06 (particulars). Off
                // until confirmed that the banking apps accept it.
                'particulars_reference' => false,
            ],
        ],

        'raast' => [
            'name' => 'Raast P2M (EMVCo based, Pakistan)',
            'adapter' => App\Payments\Providers\EmvcoMpmProvider::class,
            'rules' => [
                'country_code' => 'PK',
                'currency_numeric' => '586',
            ],
        ],

        'emvco' => [
            'name' => 'EMVCo Merchant QR (generic)',
            'adapter' => App\Payments\Providers\EmvcoMpmProvider::class,
            'rules' => [],
        ],

    ],

];
