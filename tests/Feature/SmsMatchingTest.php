<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\SmsDevice;
use App\Models\SmsMessage;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class SmsMatchingTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gateway.allow_private_callbacks' => true]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('ok', 200)]);
    }

    private function key(): string
    {
        $device = new SmsDevice(['name' => 'easypaisa phone', 'is_active' => true]);
        $key = $device->issueKey();
        $device->save();

        return $key;
    }

    private int $accountSeq = 0;

    private function pendingPayment(string $amount = '1500.00', array $data = [], ?string $claimed = null): Payment
    {
        [$merchant, $secret] = $this->makeMerchant();
        // A unique account each time, so two same-amount payments can coexist.
        $this->makeAccount('PK00DEMO'.str_pad((string) ++$this->accountSeq, 16, '0', STR_PAD_LEFT));
        $this->createPayment($merchant, $secret, $data + ['amount' => $amount, 'callback_url' => 'https://shop.example.com/cb'])->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();
        if ($claimed) {
            $payment->forceFill(['claimed_phone' => $claimed])->save();
        }

        return $payment;
    }

    private function receive(string $key, string $body, ?string $amount = null)
    {
        return $this->withToken($key)->postJson('/api/v1/sms', [
            'message_id' => \Illuminate\Support\Str::uuid()->toString(),
            'sender' => 'easypaisa',
            'body' => $body,
            'received_at' => now()->getTimestampMs(),
        ]);
    }

    public function test_parsing_pulls_amount_phone_reference_and_direction(): void
    {
        $data = SmsMessage::parse('Dear Customer, Rs. 1,500.00 received from 0300-1234567 Muhammad Ali. TID: 998877ABC. Balance Rs 4,320.00');

        $this->assertSame('1500.00', $data['amount']);
        $this->assertSame('03001234567', $data['payer_phone']);
        $this->assertSame('998877ABC', $data['bank_ref']);
        $this->assertSame('credit', $data['direction']);

        $this->assertSame('03451234567', SmsMessage::detectPayerPhone('From +923451234567'));
        $this->assertSame('debit', SmsMessage::direction('You have sent Rs 500 to 03001234567'));
    }

    public function test_a_credit_sms_auto_verifies_a_unique_pending_payment_and_sends_the_callback(): void
    {
        $key = $this->key();
        $payment = $this->pendingPayment('1500.00');

        $this->receive($key, 'Rs. 1,500.00 received in your account from 03001234567 Ali. TID 556677.')
            ->assertCreated()
            ->assertJsonPath('auto_verified', true);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('sms', $payment->verified_by);
        $this->assertSame('556677', $payment->verification_reference);
        $this->assertSame('03001234567', $payment->payer_phone);

        $sms = SmsMessage::query()->sole();
        $this->assertTrue($sms->auto_verified);
        $this->assertSame($payment->id, $sms->matched_payment_id);
        $this->assertGreaterThanOrEqual(80, $sms->match_score);
        $this->assertSame($sms->id, $payment->sms_message_id);

        // The merchant callback was sent for the paid payment.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://shop.example.com/cb' && $r['event'] === 'payment.paid');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.sms_verified']);
    }

    public function test_two_pending_payments_of_the_same_amount_are_never_auto_verified(): void
    {
        $key = $this->key();
        $a = $this->pendingPayment('1500.00', ['order_id' => 'A']);
        $b = $this->pendingPayment('1500.00', ['order_id' => 'B']);

        $this->receive($key, 'Rs 1500 received from 03001234567. TID 1.')->assertJsonPath('auto_verified', false);

        $this->assertSame(PaymentStatus::Pending, $a->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $b->fresh()->status);
        $this->assertNull(SmsMessage::query()->sole()->matched_payment_id);
        Http::assertNothingSent();
    }

    public function test_a_debit_sms_never_verifies_even_with_a_unique_match(): void
    {
        $key = $this->key();
        $payment = $this->pendingPayment('1500.00');

        $this->receive($key, 'You have sent Rs. 1,500.00 to 03001234567. TID 9.')->assertJsonPath('auto_verified', false);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_an_amount_that_matches_nothing_is_just_stored(): void
    {
        $key = $this->key();
        $this->pendingPayment('1500.00');

        $this->receive($key, 'Rs. 999.00 received from 03001234567.')->assertJsonPath('matched', false);

        $this->assertNull(SmsMessage::query()->sole()->matched_payment_id);
    }

    public function test_auto_verify_can_be_turned_off(): void
    {
        app(Settings::class)->set(['verification.sms_auto_verify' => false]);
        $key = $this->key();
        $payment = $this->pendingPayment('1500.00');

        $this->receive($key, 'Rs. 1,500.00 received from 03001234567. TID 7.')->assertJsonPath('auto_verified', false);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        // Still recorded as a suggestion.
        $this->assertSame($payment->id, SmsMessage::query()->sole()->matched_payment_id);
        Http::assertNothingSent();
    }

    public function test_the_threshold_is_respected(): void
    {
        app(Settings::class)->set(['verification.sms_threshold' => 100]);
        $key = $this->key();
        $payment = $this->pendingPayment('1500.00');

        // Expire it (within grace): a unique expired match scores 90, below a 100 threshold.
        $this->travel(6)->minutes();
        app(\App\Payments\PaymentService::class)->expireLapsed();
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);

        $this->receive($key, 'Rs. 1,500.00 received from 03001234567. TID 7.')->assertJsonPath('auto_verified', false);

        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
    }

    public function test_the_admin_sees_the_payer_phone_and_link_on_the_transaction(): void
    {
        $this->actingAs($this->makeAdmin());
        $key = $this->key();
        $payment = $this->pendingPayment('1500.00');
        $this->receive($key, 'Rs. 1,500.00 received from 03001234567 Ali. TID 556677.')->assertJsonPath('auto_verified', true);

        $this->get(route('admin.transactions.show', $payment->fresh()))
            ->assertOk()
            ->assertSee('03001234567')
            ->assertSee('SMS (auto)')
            ->assertSee('556677');

        $this->get(route('admin.sms.index'))
            ->assertOk()
            ->assertSee('Auto-verified')
            ->assertSee('03001234567')
            ->assertSee($payment->transaction_id);
    }

    public function test_an_sms_from_a_personal_mobile_number_is_not_trusted(): void
    {
        $key = $this->key();
        $payment = $this->pendingPayment('1500.00');

        // Sender is an 11-digit mobile, not an authorized short code / sender ID.
        $this->withToken($key)->postJson('/api/v1/sms', [
            'message_id' => \Illuminate\Support\Str::uuid()->toString(),
            'sender' => '03009998877',
            'body' => 'Rs. 1,500.00 received from 03001234567. TID 1.',
            'received_at' => now()->getTimestampMs(),
        ])->assertJsonPath('auto_verified', false);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_the_payer_number_identifies_the_right_order_among_same_amount(): void
    {
        $key = $this->key();
        // Two orders, same amount, different payer numbers entered at checkout.
        $ali = $this->pendingPayment('1500.00', ['order_id' => 'ALI'], claimed: '03001111111');
        $sana = $this->pendingPayment('1500.00', ['order_id' => 'SANA'], claimed: '03002222222');

        $this->receive($key, 'Rs. 1,500.00 received from 0300-2222222 Sana. TID 44.')
            ->assertJsonPath('auto_verified', true);

        // Only Sana's order (matching number) is verified; Ali's stays pending.
        $this->assertSame(PaymentStatus::Paid, $sana->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $ali->fresh()->status);
        $this->assertSame('03002222222', $sana->fresh()->payer_phone);
        $this->assertStringContainsString('matches the number the customer entered', $sana->fresh()->verification_reason);
    }

    public function test_several_orders_from_the_same_number_verify_the_latest(): void
    {
        $key = $this->key();
        // One customer (same number) placed several same-amount orders in the
        // window; the payment should land on the most recent one.
        $first = $this->pendingPayment('1500.00', ['order_id' => 'O1'], claimed: '03001234567');
        $second = $this->pendingPayment('1500.00', ['order_id' => 'O2'], claimed: '03001234567');
        $latest = $this->pendingPayment('1500.00', ['order_id' => 'O3'], claimed: '03001234567');

        $this->receive($key, 'Rs. 1,500.00 received from 03001234567 Ali. TID 9.')
            ->assertJsonPath('auto_verified', true);

        $this->assertSame(PaymentStatus::Paid, $latest->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $first->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $second->fresh()->status);
        $this->assertStringContainsString('most recent of several open orders', $latest->fresh()->verification_reason);
    }

    public function test_a_unique_amount_order_still_verifies_when_the_payer_number_differs(): void
    {
        $key = $this->key();
        // The customer entered one number but paid from another. With only one
        // open order of this amount, the amount alone still identifies it.
        $payment = $this->pendingPayment('1500.00', claimed: '03001111111');

        $this->receive($key, 'Rs. 1,500.00 received from 03009999999 Someone. TID 5.')
            ->assertJsonPath('auto_verified', true);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('03009999999', $payment->payer_phone);
        $this->assertStringContainsString('the customer had entered 03001111111', $payment->verification_reason);
    }

    public function test_same_amount_orders_with_an_unmatched_number_stay_ambiguous(): void
    {
        $key = $this->key();
        // Two open orders of the same amount; the SMS number matches neither,
        // so the payment cannot be attributed automatically.
        $a = $this->pendingPayment('1500.00', ['order_id' => 'A'], claimed: '03001111111');
        $b = $this->pendingPayment('1500.00', ['order_id' => 'B'], claimed: '03002222222');

        $this->receive($key, 'Rs. 1,500.00 received from 03009999999 Someone. TID 5.')
            ->assertJsonPath('auto_verified', false);

        $this->assertSame(PaymentStatus::Pending, $a->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $b->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_admin_can_change_auto_verify_settings(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.sms.settings'), ['sms_auto_verify' => '0', 'sms_threshold' => 95])
            ->assertSessionHas('success');

        $this->assertFalse((bool) app(Settings::class)->get('verification.sms_auto_verify'));
        $this->assertSame(95, app(Settings::class)->get('verification.sms_threshold'));
    }
}
