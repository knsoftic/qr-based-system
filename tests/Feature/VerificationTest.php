<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private string $verifierKey = 'vk_test_verifier_key_0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config(['gateway.allow_private_callbacks' => true]);
        app(Settings::class)->set(['verification.key_hash' => hash('sha256', $this->verifierKey)]);
    }

    public function test_a_payment_is_verified_and_the_merchant_receives_a_signed_callback(): void
    {
        Http::fake(['shop.example.com/*' => Http::response('ok', 200)]);

        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret, ['callback_url' => 'https://shop.example.com/cb'])->assertCreated();
        $payment = Payment::query()->firstOrFail();

        $this->postJson('/api/v1/verifications', ['transaction_id' => $payment->transaction_id, 'reference' => 'BANK-1'], ['X-Verifier-Key' => $this->verifierKey])
            ->assertOk()
            ->assertJsonPath('result', 'verified')
            ->assertJsonPath('data.status', 'paid');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('BANK-1', $payment->verification_reference);
        $this->assertNotNull($payment->callback_sent_at);

        Http::assertSent(function (Request $request) use ($secret) {
            $expected = hash_hmac('sha256', $request->header('X-Timestamp')[0].'.'.$request->body(), $secret);

            return $request->url() === 'https://shop.example.com/cb'
                && $request['event'] === 'payment.paid'
                && hash_equals($expected, $request->header('X-Signature')[0]);
        });

        $this->assertDatabaseHas('callback_deliveries', ['payment_id' => $payment->id, 'success' => true, 'response_code' => 200]);
    }

    public function test_verification_by_unique_amount(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret, ['amount' => 777, 'order_id' => 'U-1'])->assertCreated();

        $this->postJson('/api/v1/verifications', ['amount' => 777], ['X-Verifier-Key' => $this->verifierKey])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
    }

    public function test_ambiguous_amount_matches_are_refused(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret, ['amount' => 50, 'order_id' => 'D-1'])->assertCreated();
        $this->createPayment($merchant, $secret, ['amount' => 50, 'order_id' => 'D-2'])->assertCreated();

        $this->postJson('/api/v1/verifications', ['amount' => 50], ['X-Verifier-Key' => $this->verifierKey])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ambiguous_match');

        $this->assertSame(0, Payment::query()->where('status', 'paid')->count());
    }

    public function test_late_payments_within_the_grace_window_can_be_verified(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret)->assertCreated();
        $payment = Payment::query()->firstOrFail();

        $this->travel(7)->minutes();

        $this->postJson('/api/v1/verifications', ['transaction_id' => $payment->transaction_id], ['X-Verifier-Key' => $this->verifierKey])
            ->assertOk()
            ->assertJsonPath('result', 'verified_late');

        $this->travel(1)->hours();
        $this->createPayment($merchant, $secret, ['order_id' => 'LATE-2'])->assertCreated();
        $old = Payment::query()->latest('id')->first();
        $this->travel(30)->minutes();

        $this->postJson('/api/v1/verifications', ['transaction_id' => $old->transaction_id], ['X-Verifier-Key' => $this->verifierKey])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'verification_window_closed');
    }

    public function test_amount_mismatch_is_refused(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret, ['amount' => 100])->assertCreated();
        $payment = Payment::query()->firstOrFail();

        $this->postJson('/api/v1/verifications', ['transaction_id' => $payment->transaction_id, 'amount' => 90], ['X-Verifier-Key' => $this->verifierKey])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'amount_mismatch');
    }

    public function test_an_invalid_verifier_key_is_rejected(): void
    {
        $this->postJson('/api/v1/verifications', ['amount' => 10], ['X-Verifier-Key' => 'nope'])
            ->assertUnauthorized();
    }
}
