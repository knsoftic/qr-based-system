<?php

namespace Tests\Feature;

use App\Models\CallbackDelivery;
use App\Models\Merchant;
use App\Models\Payment;
use App\Payments\CallbackSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class SandboxCallbackTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private const PASSWORD = 'portal-pass-123';

    private string $secret;

    private function portalMerchant(array $attributes = []): Merchant
    {
        [$merchant, $this->secret] = $this->makeMerchant($attributes + ['name' => 'Sandbox Shop', 'username' => 'sbxshop', 'portal_enabled' => true]);
        $merchant->forceFill(['password' => self::PASSWORD])->save();
        $this->post(route('merchant.login.attempt'), ['username' => 'sbxshop', 'password' => self::PASSWORD]);

        return $merchant->fresh();
    }

    public function test_a_signed_sandbox_callback_is_sent_without_touching_money(): void
    {
        // The URL guard does a DNS lookup; this test is about the callback itself.
        config(['gateway.allow_private_callbacks' => true]);
        Http::fake(['shop.example.com/*' => Http::response('ok', 200)]);
        $merchant = $this->portalMerchant(['callback_url' => 'https://shop.example.com/cb']);

        $this->post(route('merchant.verify.callback'), ['cb_event' => 'paid', 'cb_amount' => '250', 'cb_order_id' => 'MY-ORDER-7'])
            ->assertRedirect(route('merchant.verify').'#callback')
            ->assertSessionHas('callback_test', fn ($cb) => $cb['success'] && $cb['code'] === 200);

        Http::assertSent(function (Request $request) {
            $json = json_decode($request->body(), true);
            $expected = CallbackSender::signature($this->secret, $request->header('X-Timestamp')[0], $request->body());

            return $request->url() === 'https://shop.example.com/cb'
                && $request->header('X-Sandbox')[0] === 'true'
                && $request->header('X-Signature')[0] === $expected
                && $json['sandbox'] === true
                && $json['event'] === 'payment.paid'
                && $json['data']['order_id'] === 'MY-ORDER-7'
                && $json['data']['amount'] === '250.00';
        });

        // Nothing real happened.
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, CallbackDelivery::query()->count());
        $this->assertSame('0.00', $merchant->fresh()->balance()['available']);
    }

    public function test_the_response_of_a_failing_server_is_shown(): void
    {
        config(['gateway.allow_private_callbacks' => true]);
        Http::fake(['shop.example.com/*' => Http::response('boom', 500)]);
        $this->portalMerchant(['callback_url' => 'https://shop.example.com/cb']);

        $this->post(route('merchant.verify.callback'), ['cb_event' => 'expired', 'cb_amount' => '100'])
            ->assertSessionHas('callback_test', fn ($cb) => ! $cb['success'] && $cb['code'] === 500 && $cb['body'] === 'boom');
    }

    public function test_it_needs_a_safe_callback_url(): void
    {
        Http::fake();
        $this->portalMerchant(); // no default callback URL

        $this->post(route('merchant.verify.callback'), ['cb_event' => 'paid', 'cb_amount' => '100'])
            ->assertSessionHas('error');

        // Credentials in the URL are always refused by the URL guard.
        $this->post(route('merchant.verify.callback'), ['cb_event' => 'paid', 'cb_amount' => '100', 'cb_url' => 'https://user:pass@shop.example.com/cb'])
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }
}
