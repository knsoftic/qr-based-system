<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class CheckoutPhoneTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private function payment(): Payment
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret, ['amount' => 1500])->assertCreated();

        return Payment::query()->sole();
    }

    public function test_the_checkout_prompts_for_the_pay_from_number(): void
    {
        $payment = $this->payment();

        $this->get(route('checkout.show', $payment->payment_id))
            ->assertOk()
            ->assertSee('Enter the mobile number you will pay from')
            ->assertSee('account you will send this payment from');
    }

    public function test_the_customer_can_save_the_pay_from_number(): void
    {
        $payment = $this->payment();

        $this->post(route('checkout.phone', $payment->payment_id), ['claimed_phone' => '0300-1234567'])
            ->assertRedirect(route('checkout.show', $payment->payment_id));

        $this->assertSame('03001234567', $payment->fresh()->claimed_phone);
        $this->assertDatabaseHas('payment_events', ['payment_id' => $payment->id, 'event' => 'payer_number_entered']);

        // After saving, the page shows it and no longer prompts.
        $this->get(route('checkout.show', $payment->payment_id))
            ->assertSee('Pay from')
            ->assertSee('03001234567')
            ->assertDontSee('Enter the mobile number you will pay from');
    }

    public function test_an_invalid_number_is_rejected(): void
    {
        $payment = $this->payment();

        $this->post(route('checkout.phone', $payment->payment_id), ['claimed_phone' => '12345'])
            ->assertSessionHasErrors('claimed_phone');

        $this->assertNull($payment->fresh()->claimed_phone);
    }

    public function test_a_number_cannot_be_set_on_a_finished_payment(): void
    {
        $payment = $this->payment();
        $payment->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        $this->post(route('checkout.phone', $payment->payment_id), ['claimed_phone' => '03001234567'])
            ->assertRedirect(route('checkout.show', $payment->payment_id));

        $this->assertNull($payment->fresh()->claimed_phone);
    }
}
