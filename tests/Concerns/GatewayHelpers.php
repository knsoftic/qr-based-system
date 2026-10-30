<?php

namespace Tests\Concerns;

use App\Enums\DecodeStatus;
use App\Models\Merchant;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Payments\Emv\Crc16;
use App\Payments\Emv\Tlv;
use App\Payments\Providers\ProviderRegistry;
use App\Payments\Raast\Iban;
use App\Payments\Qr\QrRenderer;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

trait GatewayHelpers
{
    protected function makeAdmin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    /**
     * @return array{0: Merchant, 1: string} merchant and plain API secret
     */
    protected function makeMerchant(array $attributes = []): array
    {
        // Default to allowing the test client IP on both sides so existing tests
        // exercise the happy path; IP tests override these to test blocking.
        $merchant = new Merchant($attributes + [
            'name' => 'Test Shop',
            'is_active' => true,
            'payin_ip_allowlist' => '127.0.0.1',
            'payout_ip_allowlist' => '127.0.0.1',
        ]);
        $secret = $merchant->issueCredentials();
        $merchant->save();

        return [$merchant, $secret];
    }

    /**
     * Give a merchant withdrawable balance by recording a paid payment worth
     * $net (net of fees), on a per-merchant demo account.
     */
    protected function fundMerchant(Merchant $merchant, float $net): void
    {
        $account = $this->makeAccount('PK00DEMO'.str_pad((string) $merchant->id, 16, '0', STR_PAD_LEFT));
        $id = uniqid();

        $merchant->payments()->create([
            'payment_id' => 'pay_'.$id, 'transaction_id' => 'TXN'.$id, 'payment_account_id' => $account->id,
            'order_id' => 'FUND-'.$id, 'amount' => $net, 'currency' => 'PKR', 'fee_amount' => 0, 'net_amount' => $net,
            'status' => 'paid', 'qr_payload' => 'x', 'idempotency_key' => 'fund-'.$id, 'request_hash' => hash('sha256', $id),
            'expires_at' => now()->addMinutes(5), 'paid_at' => now(),
        ]);
    }

    protected function makeAccount(string $accountNumber = 'PK00DEMO0000001234567890', array $attributes = []): PaymentAccount
    {
        $payload = DemoSeeder::samplePayload($accountNumber);
        $parsed = app(ProviderRegistry::class)->make('raast')->inspect($payload);

        $account = new PaymentAccount($attributes + ['name' => 'Account '.$accountNumber, 'provider' => 'raast', 'priority' => 100]);
        $account->forceFill([
            'is_active' => $attributes['is_active'] ?? true,
            'decode_status' => DecodeStatus::Decoded,
            'raw_payload' => $payload,
            'payload_template' => $parsed->template,
            'payload_hash' => hash('sha256', $payload),
            'payload_summary' => $parsed->summary,
            'decoded_at' => now(),
        ])->save();

        return $account;
    }

    /**
     * Raast P2P payload in the same layout easypaisa issues, with a fake (but
     * checksum-valid) IBAN.
     */
    protected function p2pPayload(string $bban = 'TMFB0000001234567890', ?string $amount = '100', ?string $expiry = '221120262043', array $extra = []): string
    {
        $items = [
            ['id' => '00', 'value' => '02'],
            ['id' => '01', 'value' => $amount === null ? '11' : '12'],
            ['id' => '02', 'value' => '00'],
            ['id' => '04', 'value' => Iban::make($bban)],
        ];

        if ($amount !== null) {
            $items[] = ['id' => '05', 'value' => $amount];
        }
        if ($expiry !== null) {
            $items[] = ['id' => '07', 'value' => $expiry];
        }
        foreach ($extra as $id => $value) {
            $items = Tlv::put($items, (string) $id, $value);
        }

        return Crc16::seal(Tlv::encode($items), '10');
    }

    protected function makeP2pAccount(string $bban = 'TMFB0000001234567890'): PaymentAccount
    {
        $payload = $this->p2pPayload($bban);
        $parsed = app(ProviderRegistry::class)->make('raast_p2p')->inspect($payload);

        $account = new PaymentAccount(['name' => 'P2P '.$bban, 'provider' => 'raast_p2p', 'priority' => 100]);
        $account->forceFill([
            'is_active' => true,
            'decode_status' => DecodeStatus::Decoded,
            'raw_payload' => $payload,
            'payload_template' => $parsed->template,
            'payload_hash' => hash('sha256', $payload),
            'payload_summary' => $parsed->summary,
            'decoded_at' => now(),
        ])->save();

        return $account;
    }

    protected function qrUpload(string $payload, string $name = 'qr.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qr').'.png';
        file_put_contents($path, app(QrRenderer::class)->png($payload));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    protected function signedJson(string $method, string $uri, Merchant $merchant, string $secret, ?array $body = null, array $headers = [], ?int $timestamp = null): TestResponse
    {
        $raw = $body === null ? '' : json_encode($body);
        $timestamp = (string) ($timestamp ?? time());
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, $method, $uri, $raw]), $secret);

        $server = $this->transformHeadersToServerVars($headers + [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Api-Key' => $merchant->api_key,
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signature,
        ]);

        return $this->call($method, $uri, [], [], [], $server, $raw);
    }

    protected function createPayment(Merchant $merchant, string $secret, array $body = [], ?string $key = null): TestResponse
    {
        return $this->signedJson('POST', '/api/v1/payments', $merchant, $secret,
            $body + ['amount' => 1500, 'order_id' => 'ORDER-1'],
            ['Idempotency-Key' => $key ?? 'key-'.uniqid()],
        );
    }
}
