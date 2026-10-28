<?php

namespace Tests\Concerns;

use App\Enums\DecodeStatus;
use App\Models\Merchant;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Payments\Providers\ProviderRegistry;
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
        $merchant = new Merchant($attributes + ['name' => 'Test Shop', 'is_active' => true]);
        $secret = $merchant->issueCredentials();
        $merchant->save();

        return [$merchant, $secret];
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
