# QR Based System: Mini Payment Gateway

A lightweight QR payment gateway built with **Laravel 12 + MySQL**.

The admin uploads an authorized merchant QR once. The system decodes it, verifies the payload and saves it as an encrypted template. Every payment request then gets its own **dynamic QR** with the exact amount and a unique transaction ID, valid for **5 minutes**.

```
QR Upload → QR Decode → Payload Extract → Payload Template → Dynamic Amount → Transaction ID
          → 5 Minute Expiry → Checkout QR → Payment Verification → Callback / Status Update
```

## Features

| Area | What you get |
|---|---|
| **QR upload** | Upload a QR image (PNG/JPG/GIF/WEBP). It is decoded on the server (GD), so nobody types a payload by hand. |
| **Payload verification** | A provider adapter identifies the format (EMVCo Merchant Presented Mode) and verifies the structure, the mandatory fields and the CRC checksum. Unknown or edited QR codes are marked *Unsupported* and can never be activated. |
| **Dynamic QR** | Changes only the fields the spec allows: `01` initiation method, `54` amount, `62.05` reference (transaction ID) and `63` CRC. Everything else is copied unchanged. |
| **Multiple accounts** | Add 1, 10 or 20+ accounts. Routing can be round robin, priority or random, with per-account daily limits. Accounts that already have an open payment for the same amount are skipped when possible, so bank credits match one payment. |
| **Checkout page** | Shows the amount, payment ID, transaction ID, QR, a live countdown and the status. When the QR expires it is removed and a message is shown. After payment the customer is redirected to the `return_url`. |
| **Merchant API** | HMAC-SHA256 signed requests, a timestamp window, replay protection, idempotency keys, rate limiting and validation. |
| **Verification** | Manual verification by an admin, or a verification API for a trusted bank-notification forwarder (match by transaction ID or by a unique amount). |
| **Callbacks** | Signed `POST` to the merchant on every status change, with retries and exponential backoff. URLs are checked against SSRF. |
| **Admin panel** | Dashboard, payment accounts, QR decode status, merchants and API keys, transactions (filter and CSV export), Pay-In / Pay-Out / Fees / API / Verification settings, reports, transaction, callback and audit logs, admin users. |
| **Security** | Secrets and payloads encrypted at rest, CSRF, encrypted sessions, login throttling, audit log of every admin action, security headers. Customers never see backend account details. |

## Requirements

- PHP 8.2+ with `gd`, `bcmath`, `mbstring`, `openssl`, `pdo_mysql`, `fileinfo`
- MySQL 5.7+ / MariaDB 10.4+
- Composer 2

## Installation (XAMPP / local)

```bash
git clone https://github.com/knsoftic/qr-based-system.git
cd qr-based-system
composer install
cp .env.example .env
php artisan key:generate
```

Create a database (e.g. `rastqr`) and set `DB_*` in `.env`. Set `ADMIN_EMAIL` and `ADMIN_PASSWORD`, then:

```bash
php artisan migrate --seed
php artisan serve
```

Open `http://127.0.0.1:8000/admin` and sign in.

Optional demo data (a demo merchant and a demo account built from a sample QR with fake identifiers):

```bash
php artisan db:seed --class=DemoSeeder
```

### Scheduler

Pending payments also expire on their own whenever they are read. To expire them in the background and retry failed callbacks, run the scheduler:

```bash
php artisan schedule:work            # development
* * * * * php /path/to/artisan schedule:run   # production cron
```

> **Important:** `APP_KEY` encrypts merchant secrets and QR payloads. Back it up. If you change it, that data can no longer be decrypted.

## Production deployment (aaPanel)

Ready-made files for **https://pkpay.site** at `/www/wwwroot/pkpay.site`:

| File | Purpose |
|---|---|
| [`.env.production.example`](.env.production.example) | Production `.env` template (`APP_DEBUG=false`, https, secure cookies) |
| [`deploy/aapanel-nginx-rewrite.conf`](deploy/aapanel-nginx-rewrite.conf) | aaPanel URL rewrite rule (same as the built-in `laravel5`) |
| [`deploy/install.sh`](deploy/install.sh) | First install: extension check, composer, key, migrate, caches, permissions, cron job |
| [`deploy/cron.sh`](deploy/cron.sh) | Sets the scheduler cron job (every minute, as user `www`); safe to re-run |
| [`deploy/update.sh`](deploy/update.sh) | Later updates: maintenance mode, `git pull`, composer, migrate, caches |

In short: set the site's running directory to `/public`, enable `putenv` / `proc_open` in PHP, install `fileinfo`, clone the repo, `cp .env.production.example .env`, run `bash deploy/install.sh` (it also sets the cron job), then create the admin with `php artisan gateway:create-admin`.

## Using it

1. **Admin → Payment Accounts → Add account**: choose a provider, upload the authorized QR. The decode status shows *Decoded*, *Unsupported* or *Failed*.
2. **Admin → Merchants → Add merchant**: copy the API key and secret (the secret is shown once).
3. The merchant website calls the API (see `/docs` in the app, or below) and redirects the customer to `checkout_url`.
4. Verify payments manually on the transaction page, or with the verification API (**Settings → Verification**).

## Merchant API (summary)

Full docs with a PHP example are served at **`/docs`**.

```
POST /api/v1/payments          create payment → payment_id, transaction_id, checkout_url
GET  /api/v1/payments/{id}     payment status
POST /api/v1/verifications     confirm a payment (verifier key)
```

Headers: `X-Api-Key`, `X-Timestamp`, `X-Signature`, and `Idempotency-Key` on POST.

```
X-Signature = hex( HMAC-SHA256( api_secret,
                  timestamp + "\n" + METHOD + "\n" + REQUEST_URI + "\n" + RAW_BODY ) )
```

Callbacks carry `X-Timestamp` and `X-Signature = hex(HMAC-SHA256(api_secret, timestamp + "." + body))`.

## QR providers

Providers are configured in [`config/gateway.php`](config/gateway.php). Each one maps to an isolated adapter in [`app/Payments/Providers`](app/Payments/Providers):

- `emvco`: generic EMVCo Merchant Presented Mode.
- `raast`: the same adapter, restricted to country `PK` and currency `586` (PKR).

To support a new scheme, add an adapter that implements `QrProvider` and register it in the config. Only add rules that come from the provider's official specification.

## Tests

```bash
php artisan test
```

The tests cover the CRC, TLV and adapter rules, real QR image upload and decode, API signing, replay, idempotency, validation, routing, limits and fees, checkout expiry, verification, signed callbacks, and the admin pages.

## Project structure

```
app/Payments/Emv/            TLV parser/encoder, CRC-16/CCITT
app/Payments/Providers/      QrProvider interface, EMVCo adapter, registry
app/Payments/Qr/             QR image decoder and renderer
app/Payments/                AccountQrProcessor, AccountRouter, PaymentService, fees, callbacks
app/Http/Middleware/         Merchant signature auth, verifier auth, security headers
app/Http/Controllers/Api/    Merchant + verification API
app/Http/Controllers/Admin/  Admin panel
resources/views/checkout/    Customer checkout page
```

## Roadmap

Multiple payment providers · automatic reconciliation · webhook subscriptions · merchant-wise limits · daily / monthly limits · payout processing · advanced analytics.

## License

MIT
