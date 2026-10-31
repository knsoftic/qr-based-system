<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class ShortLinkTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private function payment(array $body = []): Payment
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret, $body + ['amount' => 2500])->assertCreated();

        return Payment::query()->firstOrFail();
    }

    public function test_the_api_returns_a_slug_and_short_url(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $res = $this->createPayment($merchant, $secret, ['amount' => 1500])->assertCreated();

        $payment = Payment::query()->firstOrFail();
        $this->assertNotNull($payment->slug);
        $this->assertStringContainsString('/s/'.$payment->slug, $res->json('data.short_url'));
    }

    public function test_the_short_link_renders_the_checkout(): void
    {
        $payment = $this->payment();

        $this->get('/s/'.$payment->slug)
            ->assertOk()
            ->assertSee('2,500.00')
            ->assertSee('class="qr-svg', false)
            // The page's own status/phone endpoints stay slug-based, not payment-id based.
            ->assertSee('/s/'.$payment->slug.'/status', false)
            ->assertDontSee('/checkout/'.$payment->payment_id.'/status', false);
    }

    public function test_short_status_and_phone_work(): void
    {
        $payment = $this->payment();

        $this->getJson('/s/'.$payment->slug.'/status')->assertOk()->assertJsonPath('status', 'pending');

        $this->post('/s/'.$payment->slug.'/phone', ['claimed_phone' => '03001234567'])
            ->assertRedirect(route('checkout.short', $payment->slug)); // stays on the short URL
        $this->assertSame('03001234567', $payment->fresh()->claimed_phone);
    }

    public function test_short_url_uses_a_configured_neutral_domain(): void
    {
        app(Settings::class)->set(['checkout.short_base_url' => 'https://pay.example.com']);
        $payment = $this->payment();

        $this->assertSame('https://pay.example.com/s/'.$payment->slug, $payment->shortUrl());
    }

    public function test_the_long_checkout_url_still_works(): void
    {
        $payment = $this->payment();

        $this->get(route('checkout.show', $payment->payment_id))->assertOk()->assertSee('2,500.00');
    }

    public function test_an_unknown_slug_returns_404(): void
    {
        $this->get('/s/doesnotexist9')->assertNotFound();
    }
}
