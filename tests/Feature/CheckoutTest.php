<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private function newPayment(array $body = []): Payment
    {
        [$merchant, $secret] = $this->makeMerchant(['name' => 'Demo Shop']);
        $this->makeAccount();
        $this->createPayment($merchant, $secret, $body + ['amount' => 2500, 'order_id' => 'CHK-1'])->assertCreated();

        return Payment::query()->firstOrFail();
    }

    public function test_the_checkout_page_shows_amount_ids_qr_and_timer(): void
    {
        $payment = $this->newPayment();

        $this->get(route('checkout.show', $payment->payment_id))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('2,500.00')
            ->assertSee($payment->payment_id)
            ->assertSee($payment->transaction_id)
            ->assertSee('Demo Shop')
            ->assertSee('<svg', false)
            ->assertSee('QR expires in')
            // Backend account details are never exposed.
            ->assertDontSee('PK00DEMO')
            ->assertDontSee('DEMO STORE')
            ->assertDontSee($payment->account->name);

        $this->assertDatabaseHas('payment_events', ['payment_id' => $payment->id, 'event' => 'checkout_viewed']);
    }

    public function test_the_qr_is_disabled_after_five_minutes(): void
    {
        $payment = $this->newPayment();

        $this->travel(301)->seconds();

        $this->get(route('checkout.show', $payment->payment_id))
            ->assertOk()
            ->assertSee('QR code expired')
            ->assertDontSee('<svg', false);

        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertDatabaseHas('payment_events', ['payment_id' => $payment->id, 'event' => 'status_changed', 'to_status' => 'expired']);
    }

    public function test_the_status_endpoint_reports_remaining_time_and_redirect(): void
    {
        $payment = $this->newPayment(['return_url' => 'https://shop.example.com/done?o=1']);

        $this->getJson(route('checkout.status', $payment->payment_id))
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('redirect_url', null);

        $this->travel(10)->minutes();

        $this->getJson(route('checkout.status', $payment->payment_id))
            ->assertJsonPath('status', 'expired')
            ->assertJsonPath('remaining_seconds', 0)
            ->assertJsonPath('redirect_url', "https://shop.example.com/done?o=1&payment_id={$payment->payment_id}&status=expired");
    }

    public function test_the_scheduled_command_expires_lapsed_payments(): void
    {
        $payment = $this->newPayment();
        $this->travel(6)->minutes();

        $this->artisan('payments:expire')->expectsOutput('Expired 1 payment(s).')->assertSuccessful();

        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
    }

    public function test_unknown_payments_return_404(): void
    {
        $this->get('/checkout/pay_doesnotexist')->assertNotFound();
    }
}
