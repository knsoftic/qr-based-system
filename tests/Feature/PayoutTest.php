<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Payout;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private const PASSWORD = 'portal-pass-123';

    private function fund(Merchant $merchant, float $net): void
    {
        $this->fundMerchant($merchant, $net);
    }

    /**
     * @return array{0: Merchant, 1: string}
     */
    private function portalMerchant(array $attributes = []): array
    {
        [$merchant, $secret] = $this->makeMerchant($attributes + ['username' => 'demostore', 'portal_enabled' => true]);
        $merchant->forceFill(['password' => self::PASSWORD])->save();

        return [$merchant->fresh(), $secret];
    }

    public function test_the_balance_api_reports_earned_frozen_and_available(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->fund($merchant, 5000);

        $this->signedJson('GET', '/api/v1/balance', $merchant, $secret)
            ->assertOk()
            ->assertJsonPath('data.earned', '5000.00')
            ->assertJsonPath('data.frozen', '0.00')
            ->assertJsonPath('data.paid_out', '0.00')
            ->assertJsonPath('data.available', '5000.00')
            ->assertJsonPath('data.currency', 'PKR');
    }

    public function test_requesting_a_payout_over_the_api_freezes_the_amount(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->fund($merchant, 5000);

        $this->signedJson('POST', '/api/v1/payouts', $merchant, $secret, [
            'method' => 'easypaisa',
            'account_number' => '0300-1234567',
            'account_name' => 'Ali Khan',
            'amount' => 2000,
        ], ['Idempotency-Key' => 'po-1'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '2000.00')
            ->assertJsonPath('data.account_number', '03001234567') // normalised
            ->assertJsonStructure(['data' => ['payout_ref', 'method', 'created_at']]);

        $this->assertDatabaseHas('payouts', ['merchant_id' => $merchant->id, 'amount' => 2000, 'status' => 'pending']);

        $balance = $merchant->fresh()->balance();
        $this->assertSame('2000.00', $balance['frozen']);
        $this->assertSame('3000.00', $balance['available']);
    }

    public function test_a_payout_over_the_available_balance_is_rejected(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->fund($merchant, 1000);

        $this->signedJson('POST', '/api/v1/payouts', $merchant, $secret, [
            'method' => 'easypaisa',
            'account_number' => '03001234567',
            'amount' => 5000,
        ], ['Idempotency-Key' => 'po-2'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_balance');

        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_payout_input_is_validated(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->fund($merchant, 5000);

        $this->signedJson('POST', '/api/v1/payouts', $merchant, $secret, [
            'method' => 'paypal',           // unsupported
            'account_number' => 'not-a-number',
            'amount' => -5,
        ], ['Idempotency-Key' => 'po-3'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['fields' => ['method', 'account_number', 'amount']]]);
    }

    public function test_payout_amount_must_be_within_the_configured_range(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->fund($merchant, 500000);

        // Below the 100 minimum.
        $this->signedJson('POST', '/api/v1/payouts', $merchant, $secret, [
            'method' => 'jazzcash', 'account_number' => '03001234567', 'amount' => 50,
        ], ['Idempotency-Key' => 'po-min'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'amount_out_of_range');
    }

    public function test_payouts_can_be_disabled(): void
    {
        app(Settings::class)->set(['payout.enabled' => false]);

        [$merchant, $secret] = $this->makeMerchant();
        $this->fund($merchant, 5000);

        $this->signedJson('POST', '/api/v1/payouts', $merchant, $secret, [
            'method' => 'easypaisa', 'account_number' => '03001234567', 'amount' => 1000,
        ], ['Idempotency-Key' => 'po-off'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'payout_disabled');
    }

    public function test_a_merchant_only_sees_its_own_payouts(): void
    {
        [$owner, $ownerSecret] = $this->makeMerchant();
        [$other, $otherSecret] = $this->makeMerchant(['name' => 'Other']);
        $this->fund($owner, 5000);

        $ref = $this->signedJson('POST', '/api/v1/payouts', $owner, $ownerSecret, [
            'method' => 'easypaisa', 'account_number' => '03001234567', 'amount' => 1000,
        ], ['Idempotency-Key' => 'po-own'])->json('data.payout_ref');

        $this->signedJson('GET', "/api/v1/payouts/{$ref}", $owner, $ownerSecret)
            ->assertOk()->assertJsonPath('data.payout_ref', $ref);

        $this->signedJson('GET', "/api/v1/payouts/{$ref}", $other, $otherSecret)
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_admin_marking_a_payout_paid_moves_it_from_frozen_to_paid_out(): void
    {
        [$merchant] = $this->makeMerchant();
        $this->fund($merchant, 5000);
        $payout = app(\App\Payments\PayoutService::class)->request($merchant, [
            'method' => 'easypaisa', 'account_number' => '03001234567', 'amount' => 2000,
        ]);

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.payouts.paid', $payout), ['reference' => 'EP-TID-99'])
            ->assertRedirect();

        $payout->refresh();
        $this->assertSame('paid', $payout->status);
        $this->assertSame('EP-TID-99', $payout->reference);
        $this->assertNotNull($payout->processed_at);

        $balance = $merchant->fresh()->balance();
        $this->assertSame('0.00', $balance['frozen']);
        $this->assertSame('2000.00', $balance['paid_out']);
        $this->assertSame('3000.00', $balance['available']); // unchanged by approval
    }

    public function test_admin_rejecting_a_payout_returns_the_amount_to_available(): void
    {
        [$merchant] = $this->makeMerchant();
        $this->fund($merchant, 5000);
        $payout = app(\App\Payments\PayoutService::class)->request($merchant, [
            'method' => 'easypaisa', 'account_number' => '03001234567', 'amount' => 2000,
        ]);

        $this->assertSame('3000.00', $merchant->fresh()->balance()['available']);

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.payouts.reject', $payout), ['admin_note' => 'wrong number'])
            ->assertRedirect();

        $payout->refresh();
        $this->assertSame('rejected', $payout->status);

        $balance = $merchant->fresh()->balance();
        $this->assertSame('0.00', $balance['frozen']);
        $this->assertSame('5000.00', $balance['available']); // returned
    }

    public function test_a_payout_cannot_be_settled_twice(): void
    {
        [$merchant] = $this->makeMerchant();
        $this->fund($merchant, 5000);
        $payout = app(\App\Payments\PayoutService::class)->request($merchant, [
            'method' => 'easypaisa', 'account_number' => '03001234567', 'amount' => 2000,
        ]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.payouts.paid', $payout))->assertRedirect();
        // A second attempt finds it no longer pending.
        $this->actingAs($admin)->post(route('admin.payouts.reject', $payout))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame('paid', $payout->fresh()->status);
    }

    public function test_a_merchant_can_request_a_withdrawal_from_the_portal(): void
    {
        [$merchant] = $this->portalMerchant();
        $this->fund($merchant, 5000);

        $this->post(route('merchant.login.attempt'), ['username' => 'demostore', 'password' => self::PASSWORD]);

        $this->post(route('merchant.payouts.request'), [
            'method' => 'jazzcash',
            'account_number' => '0345 7654321',
            'amount' => 1500,
        ])->assertRedirect()->assertSessionHas('success');

        $payout = Payout::query()->where('merchant_id', $merchant->id)->firstOrFail();
        $this->assertSame('jazzcash', $payout->method);
        $this->assertSame('03457654321', $payout->account_number);
        $this->assertSame('1500.00', $payout->amount);
        $this->assertSame('pending', $payout->status);
    }
}
