<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Payment;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class MerchantPortalTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private const PASSWORD = 'portal-pass-123';

    /**
     * @return array{0: Merchant, 1: string}
     */
    private function portalMerchant(array $attributes = []): array
    {
        [$merchant, $secret] = $this->makeMerchant($attributes + ['username' => 'demostore', 'portal_enabled' => true]);
        $merchant->forceFill(['password' => self::PASSWORD])->save();

        return [$merchant->fresh(), $secret];
    }

    private function signIn(string $username = 'demostore', string $password = self::PASSWORD)
    {
        return $this->post(route('merchant.login.attempt'), ['username' => $username, 'password' => $password]);
    }

    public function test_the_landing_page_sends_visitors_to_the_merchant_login(): void
    {
        $this->get('/')->assertRedirect('/merchant/login');

        $this->get(route('merchant.login'))
            ->assertOk()
            ->assertSee('Merchant login')
            ->assertSee('Enter your merchant details to sign in to your account.');

        // A signed-in merchant goes straight to the dashboard.
        $this->portalMerchant();
        $this->signIn();
        $this->get(route('merchant.login'))->assertRedirect(route('merchant.dashboard'));
    }

    public function test_admin_creates_a_merchant_with_a_portal_login_and_sees_the_password_once(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.merchants.store'), [
            'name' => 'Demo Store',
            'is_active' => 1,
            'portal_enabled' => 1,
            'username' => 'DemoStore',
        ])->assertSessionHas('new_portal_password');

        $merchant = Merchant::query()->sole();
        $generated = session('new_portal_password');

        $this->assertSame('demostore', $merchant->username);
        $this->assertTrue($merchant->portal_enabled);
        $this->assertMatchesRegularExpression('/^MID[A-Z0-9]{9}$/', $merchant->merchant_code);
        $this->assertTrue(Hash::check($generated, $merchant->password));
        $this->assertStringNotContainsString($generated, json_encode(\DB::table('audit_logs')->get()));

        $this->get(route('admin.merchants.show', $merchant))
            ->assertSee($merchant->merchant_code)
            ->assertSee(route('merchant.login'));
    }

    public function test_a_username_is_required_when_the_portal_is_enabled(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.merchants.store'), ['name' => 'X', 'portal_enabled' => 1])
            ->assertSessionHasErrors('username');
    }

    public function test_a_merchant_signs_in_and_sees_its_merchant_id_and_api_key_but_not_the_secret(): void
    {
        [$merchant, $secret] = $this->portalMerchant();

        $this->signIn('DemoStore')->assertRedirect(route('merchant.dashboard'));
        $this->assertAuthenticatedAs($merchant, 'merchant');

        $this->get(route('merchant.dashboard'))
            ->assertOk()
            ->assertSee($merchant->merchant_code)
            ->assertSee($merchant->api_key)
            ->assertDontSee($secret);

        $this->assertNotNull($merchant->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'merchant.portal_login', 'user_id' => null]);
    }

    public function test_the_dashboard_shows_balance_and_todays_stats(): void
    {
        [$merchant] = $this->portalMerchant();
        $this->makeAccount();

        // One paid (net 970), one pending, one expired, today.
        $merchant->payments()->create($this->paymentRow($merchant, 'P1', 'paid', ['net_amount' => 970, 'fee_amount' => 30, 'paid_at' => now()]));
        $merchant->payments()->create($this->paymentRow($merchant, 'P2', 'pending'));
        $merchant->payments()->create($this->paymentRow($merchant, 'P3', 'expired'));

        $this->signIn();
        $this->get(route('merchant.dashboard'))
            ->assertOk()
            ->assertSee('Available balance')
            ->assertSee('970.00')          // balance = net of paid
            ->assertSee('Pay-In today')
            ->assertSee('Pay-Out today')
            ->assertSee('Completed today')
            ->assertSee('Failed / expired today');
    }

    private function paymentRow(Merchant $merchant, string $order, string $status, array $extra = []): array
    {
        return array_merge([
            'payment_id' => 'pay_'.strtolower($order).uniqid(),
            'transaction_id' => 'TXN'.$order.uniqid(),
            'order_id' => $order,
            'amount' => 1000,
            'currency' => 'PKR',
            'net_amount' => 1000,
            'status' => $status,
            'idempotency_key' => $order.uniqid(),
            'request_hash' => hash('sha256', $order),
            'expires_at' => now()->addMinutes(5),
        ], $extra);
    }

    public function test_wrong_password_disabled_portal_and_inactive_merchant_cannot_sign_in(): void
    {
        [$merchant] = $this->portalMerchant();

        $this->signIn(password: 'wrong-password-1')->assertSessionHasErrors('username');

        $merchant->forceFill(['portal_enabled' => false])->save();
        $this->signIn()->assertSessionHasErrors('username');

        $merchant->forceFill(['portal_enabled' => true, 'is_active' => false])->save();
        $this->signIn()->assertSessionHasErrors('username');

        $this->assertGuest('merchant');
    }

    public function test_admin_and_merchant_logins_are_separate(): void
    {
        $this->portalMerchant();
        $this->signIn();

        $this->get('/admin')->assertRedirect(route('admin.login'));

        $this->post(route('merchant.logout'));
        Auth::forgetGuards();
        $this->actingAs($this->makeAdmin());

        $this->get('/merchant')->assertRedirect(route('merchant.login'));
    }

    public function test_an_admin_password_reset_signs_the_merchant_out(): void
    {
        [$merchant] = $this->portalMerchant();
        $this->signIn();
        $this->get(route('merchant.dashboard'))->assertOk();

        $this->actingAs($this->makeAdmin());
        $this->post(route('admin.merchants.portal-password', $merchant))->assertSessionHas('new_portal_password');

        Auth::forgetGuards();
        $this->get(route('merchant.dashboard'))->assertRedirect(route('merchant.login'));
        $this->assertGuest('merchant');
    }

    public function test_revealing_the_secret_needs_the_portal_password(): void
    {
        [, $secret] = $this->portalMerchant();
        $this->signIn();

        $this->post(route('merchant.secret.reveal'), ['password' => 'not-my-password'])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('revealed_secret');

        $this->post(route('merchant.secret.reveal'), ['password' => self::PASSWORD])
            ->assertSessionHas('revealed_secret', $secret);
    }

    public function test_rotating_the_secret_needs_confirmation(): void
    {
        [$merchant, $secret] = $this->portalMerchant();
        $this->signIn();

        $this->post(route('merchant.secret.rotate'), ['password' => self::PASSWORD])->assertSessionHasErrors('confirm');
        $this->assertSame($secret, $merchant->fresh()->api_secret);

        $this->post(route('merchant.secret.rotate'), ['password' => self::PASSWORD, 'confirm' => 1])->assertSessionHas('revealed_secret');
        $this->assertNotSame($secret, $merchant->fresh()->api_secret);
    }

    public function test_portal_test_payin_creates_a_payment_through_the_real_api(): void
    {
        [$merchant] = $this->portalMerchant();
        $account = $this->makeP2pAccount();
        $this->signIn();

        $this->post(route('merchant.test.run'), ['amount' => 25])->assertRedirect();

        $result = session('payin_test');
        $this->assertTrue($result['ok']);
        $this->assertSame(201, $result['status']);
        // Regression: the internal API call must use the site's host, not "localhost".
        $this->assertStringStartsWith(url('/checkout/'), $result['response']['data']['checkout_url']);
        $this->assertEquals(25, session()->getOldInput('amount'));

        $payment = Payment::query()->sole();
        $this->assertSame($merchant->id, $payment->merchant_id);
        $this->assertSame($account->id, $payment->payment_account_id);
        $this->assertSame('true', $payment->metadata['test']);
        $this->assertSame('portal', $payment->metadata['source']);
        $this->assertStringStartsWith('TEST-', $payment->order_id);
        $this->assertDatabaseHas('payment_events', ['payment_id' => $payment->id, 'event' => 'created', 'actor' => 'api']);

        $this->get(route('merchant.test'))
            ->assertOk()
            ->assertSee('HTTP 201')
            ->assertSee('class="qr-svg', false)
            ->assertSee($payment->payment_id)
            ->assertSee($payment->checkoutUrl())
            ->assertDontSee('PK00')
            ->assertDontSee(route('admin.transactions.show', $payment));

        // The page request is intact after the internal API call.
        $this->assertAuthenticatedAs($merchant, 'merchant');
    }

    public function test_portal_test_payin_shows_api_errors(): void
    {
        $this->portalMerchant();
        $this->signIn();

        $this->post(route('merchant.test.run'), ['amount' => 25]);

        $this->assertSame(503, session('payin_test')['status']);
        $this->get(route('merchant.test'))->assertSee('no_account_available');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_admin_can_run_a_test_payin_for_a_merchant(): void
    {
        $this->actingAs($this->makeAdmin());
        [$merchant] = $this->makeMerchant();
        $this->makeAccount();

        $this->post(route('admin.merchants.test', $merchant), ['amount' => 50])->assertRedirect();

        $payment = Payment::query()->sole();
        $this->assertSame('admin', $payment->metadata['source']);

        $this->get(route('admin.merchants.show', $merchant))
            ->assertSee('HTTP 201')
            ->assertSee('class="qr-svg', false)
            ->assertSee(route('admin.transactions.show', $payment));
        $this->assertDatabaseHas('audit_logs', ['action' => 'merchant.payin_test']);
    }

    public function test_credential_check_compares_without_echoing_the_secret(): void
    {
        [$merchant, $secret] = $this->portalMerchant();
        $this->signIn();

        $this->post(route('merchant.verify.credentials'), ['api_key' => $merchant->api_key, 'api_secret' => $secret])
            ->assertSessionHas('credential_check', ['key' => true, 'secret' => true]);

        $this->post(route('merchant.verify.credentials'), ['api_key' => $merchant->api_key, 'api_secret' => 'sk_wrong'])
            ->assertSessionHas('credential_check', ['key' => true, 'secret' => false]);

        $this->get(route('merchant.verify'))->assertSee('does not match')->assertDontSee($secret);
    }

    public function test_signature_check_rebuilds_the_signature_like_the_api(): void
    {
        [, $secret] = $this->portalMerchant();
        $this->signIn();

        $timestamp = (string) time();
        $body = "{\n  \"amount\": 1500,\n  \"order_id\": \"ORDER-1\"\n}";
        $good = hash_hmac('sha256', "{$timestamp}\nPOST\n/api/v1/payments\n{$body}", $secret);
        $fields = ['sig_timestamp' => $timestamp, 'sig_method' => 'POST', 'sig_uri' => '/api/v1/payments'];

        // Pasted from a textarea (CRLF, trailing spaces kept) still matches.
        $this->post(route('merchant.verify.signature'), $fields + ['sig_body' => str_replace("\n", "\r\n", $body), 'sig_signature' => strtoupper($good)])
            ->assertSessionHas('signature_check', fn (array $check) => $check['match'] && $check['fresh'] && $check['expected'] === $good);

        $this->post(route('merchant.verify.signature'), $fields + ['sig_body' => $body.' ', 'sig_signature' => $good])
            ->assertSessionHas('signature_check', fn (array $check) => ! $check['match']);
    }

    public function test_accounts_are_picked_at_random_by_default(): void
    {
        $this->assertSame('random', app(Settings::class)->get('payin.routing_strategy'));

        [$merchant, $secret] = $this->makeMerchant();
        $a = $this->makeAccount('PK00DEMO0000000000000001');
        $b = $this->makeAccount('PK00DEMO0000000000000002');

        $used = collect(range(1, 24))->map(function (int $i) use ($merchant, $secret) {
            $this->createPayment($merchant, $secret, ['amount' => 100 + $i, 'order_id' => "R-{$i}"])->assertCreated();

            return Payment::query()->latest('id')->value('payment_account_id');
        });

        // Different amounts, so only randomness decides: both accounts get used.
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $used->unique()->values()->all());
    }
}
