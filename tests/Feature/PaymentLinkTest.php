<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class PaymentLinkTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private const PASSWORD = 'portal-pass-123';

    private function portalMerchant(string $username = 'linkshop'): Merchant
    {
        [$merchant] = $this->makeMerchant(['name' => 'Link Shop', 'username' => $username, 'portal_enabled' => true]);
        $merchant->forceFill(['password' => self::PASSWORD])->save();

        return $merchant->fresh();
    }

    private function signIn(string $username = 'linkshop'): void
    {
        $this->post(route('merchant.login.attempt'), ['username' => $username, 'password' => self::PASSWORD]);
    }

    private function link(Merchant $merchant, array $attributes = []): PaymentLink
    {
        return $merchant->paymentLinks()->create($attributes + ['title' => 'Monthly fee', 'amount' => 1500, 'is_active' => true]);
    }

    public function test_the_merchant_creates_a_link_and_sees_it(): void
    {
        $this->portalMerchant();
        $this->signIn();

        $this->post(route('merchant.links.store'), ['title' => 'Course fee', 'amount' => 2500])
            ->assertRedirect(route('merchant.links'))->assertSessionHas('success');

        $link = PaymentLink::query()->sole();
        $this->assertSame('2500.00', $link->amount);
        $this->assertSame(10, strlen($link->code));

        $this->get(route('merchant.links'))->assertOk()->assertSee('Course fee')->assertSee('/p/'.$link->code);
    }

    public function test_opening_a_link_never_creates_a_payment(): void
    {
        $link = $this->link($this->portalMerchant());

        $this->get(route('paylink.show', $link->code))->assertOk()->assertSee('Monthly fee')->assertSee('1,500.00');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_paying_a_fixed_link_creates_a_payment_and_opens_checkout(): void
    {
        $merchant = $this->portalMerchant();
        $this->makeAccount();
        $link = $this->link($merchant);

        $response = $this->post(route('paylink.pay', $link->code), ['name' => 'Ali']);

        $payment = Payment::query()->sole();
        $response->assertRedirect($payment->shortUrl());
        $this->assertSame('1500.00', $payment->amount);
        $this->assertSame($link->id, $payment->payment_link_id);
        $this->assertSame($merchant->id, $payment->merchant_id);
        $this->assertSame('Ali', $payment->customer_name);
    }

    public function test_an_open_link_takes_the_customer_amount_within_limits(): void
    {
        $merchant = $this->portalMerchant();
        $this->makeAccount();
        $link = $this->link($merchant, ['amount' => null]);

        $this->post(route('paylink.pay', $link->code), ['amount' => 0])->assertSessionHasErrors('amount');
        $this->post(route('paylink.pay', $link->code), ['amount' => 750.5])->assertRedirect();

        $this->assertSame('750.50', Payment::query()->sole()->amount);
    }

    public function test_an_inactive_or_expired_link_cannot_be_paid(): void
    {
        $merchant = $this->portalMerchant();
        $this->makeAccount();
        $off = $this->link($merchant, ['is_active' => false]);
        $old = $this->link($merchant, ['expires_at' => now()->subDay()]);

        $this->get(route('paylink.show', $off->code))->assertOk()->assertSee('This link is not active');
        $this->post(route('paylink.pay', $off->code))->assertSessionHas('error');
        $this->post(route('paylink.pay', $old->code))->assertSessionHas('error');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_merchant_cannot_touch_another_merchants_link(): void
    {
        $other = $this->link($this->portalMerchant('othershop'));
        $this->portalMerchant();
        $this->signIn();

        $this->post(route('merchant.links.toggle', $other->id))->assertNotFound();
        $this->get(route('merchant.links.qr', $other->id))->assertNotFound();
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_the_link_qr_is_a_png(): void
    {
        $link = $this->link($this->portalMerchant());
        $this->signIn();

        $this->get(route('merchant.links.qr', $link->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_an_unknown_link_is_404(): void
    {
        $this->get('/p/doesnotexist')->assertNotFound();
    }
}
