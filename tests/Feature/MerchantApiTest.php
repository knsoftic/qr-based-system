<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Payments\Emv\Crc16;
use App\Payments\Emv\Tlv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class MerchantApiTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    public function test_a_signed_request_creates_a_payment_with_a_dynamic_qr(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $account = $this->makeAccount();

        $response = $this->createPayment($merchant, $secret, ['amount' => 1500.5, 'order_id' => 'ORDER-77']);

        $response->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.amount', '1500.50')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.order_id', 'ORDER-77')
            ->assertJsonStructure(['data' => ['payment_id', 'transaction_id', 'checkout_url', 'expires_at']]);

        $payment = Payment::query()->firstOrFail();
        $items = Tlv::parse($payment->qr_payload);

        $this->assertSame($account->id, $payment->payment_account_id);
        $this->assertSame('1500.50', Tlv::find($items, '54'));
        $this->assertSame($payment->transaction_id, Tlv::find(Tlv::parse(Tlv::find($items, '62')), '05'));
        $this->assertTrue(Crc16::isValid($payment->qr_payload));
        $this->assertEqualsWithDelta(300, now()->diffInSeconds($payment->expires_at), 2);
        $this->assertSame(route('checkout.show', $payment->payment_id), $response->json('data.checkout_url'));

        // Account details and the payload never appear in the API response.
        $this->assertStringNotContainsString('PK00DEMO', $response->getContent());
        $this->assertStringNotContainsString('qr_payload', $response->getContent());
    }

    public function test_an_invalid_signature_is_rejected(): void
    {
        [$merchant] = $this->makeMerchant();
        $this->makeAccount();

        $this->createPayment($merchant, 'sk_wrong_secret')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_signature');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        [$merchant, $secret] = $this->makeMerchant();

        $this->signedJson('GET', '/api/v1/payments/pay_x', $merchant, $secret, timestamp: time() - 3600)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'timestamp_out_of_range');
    }

    public function test_a_replayed_signature_is_rejected(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $timestamp = time();

        $this->signedJson('GET', '/api/v1/payments/pay_x', $merchant, $secret, timestamp: $timestamp)->assertNotFound();
        $this->signedJson('GET', '/api/v1/payments/pay_x', $merchant, $secret, timestamp: $timestamp)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'replayed_request');
    }

    public function test_a_disabled_merchant_cannot_authenticate(): void
    {
        [$merchant, $secret] = $this->makeMerchant(['is_active' => false]);
        $this->makeAccount();

        $this->createPayment($merchant, $secret)->assertUnauthorized();
    }

    public function test_the_same_idempotency_key_returns_the_same_payment(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();

        $first = $this->createPayment($merchant, $secret, ['order_id' => 'A-1'], 'idem-key-123');
        $second = $this->createPayment($merchant, $secret, ['order_id' => 'A-1'], 'idem-key-123');

        $first->assertCreated();
        $second->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.payment_id'), $second->json('data.payment_id'));
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_reusing_an_idempotency_key_with_a_different_body_conflicts(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();

        $this->createPayment($merchant, $secret, ['amount' => 100], 'idem-key-456')->assertCreated();
        $this->createPayment($merchant, $secret, ['amount' => 200], 'idem-key-456')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');
    }

    public function test_the_idempotency_key_header_is_required(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();

        $this->signedJson('POST', '/api/v1/payments', $merchant, $secret, ['amount' => 10, 'order_id' => 'X'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'idempotency_key_required');
    }

    public function test_input_is_validated(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();

        $this->createPayment($merchant, $secret, ['amount' => -5, 'order_id' => 'bad id <script>'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['fields' => ['amount', 'order_id']]]);

        $this->createPayment($merchant, $secret, ['amount' => 10.123])->assertStatus(422);
        $this->createPayment($merchant, $secret, ['amount' => 999999999])->assertStatus(422);
    }

    public function test_private_callback_urls_are_rejected(): void
    {
        config(['gateway.allow_private_callbacks' => false]);
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();

        $this->createPayment($merchant, $secret, ['callback_url' => 'http://127.0.0.1/admin'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['callback_url']]]);
    }

    public function test_it_fails_cleanly_without_an_active_account(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount(attributes: ['is_active' => false]);

        $this->createPayment($merchant, $secret)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'no_account_available');
    }

    public function test_accounts_are_rotated_and_same_amount_collisions_avoided(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $a = $this->makeAccount('PK00DEMO0000000000000001');
        $b = $this->makeAccount('PK00DEMO0000000000000002');

        $this->createPayment($merchant, $secret, ['amount' => 500, 'order_id' => 'O-1'])->assertCreated();
        $this->createPayment($merchant, $secret, ['amount' => 500, 'order_id' => 'O-2'])->assertCreated();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], Payment::query()->pluck('payment_account_id')->all());
    }

    public function test_daily_limits_are_respected(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount(attributes: ['daily_limit' => 1000]);

        $this->createPayment($merchant, $secret, ['amount' => 800, 'order_id' => 'L-1'])->assertCreated();
        $this->createPayment($merchant, $secret, ['amount' => 300, 'order_id' => 'L-2'])->assertStatus(503);
    }

    public function test_merchants_can_only_read_their_own_payments(): void
    {
        [$owner, $ownerSecret] = $this->makeMerchant();
        [$other, $otherSecret] = $this->makeMerchant(['name' => 'Other']);
        $this->makeAccount();

        $paymentId = $this->createPayment($owner, $ownerSecret)->json('data.payment_id');

        $this->signedJson('GET', "/api/v1/payments/{$paymentId}", $owner, $ownerSecret)
            ->assertOk()
            ->assertJsonPath('data.payment_id', $paymentId);

        $this->signedJson('GET', "/api/v1/payments/{$paymentId}", $other, $otherSecret)->assertNotFound();
    }

    public function test_fees_are_calculated_with_merchant_overrides(): void
    {
        [$merchant, $secret] = $this->makeMerchant(['fee_percent' => 2.5, 'fee_fixed' => 10]);
        $this->makeAccount();

        $this->createPayment($merchant, $secret, ['amount' => 1000])
            ->assertJsonPath('data.fee', '35.00')
            ->assertJsonPath('data.net_amount', '965.00');
    }
}
