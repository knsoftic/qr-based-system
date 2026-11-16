<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Support\Settings;
use App\Telegram\LinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

    public function test_the_dashboard_renders_when_telegram_is_linked(): void
    {
        [$merchant] = $this->portalMerchant();
        app(LinkService::class)->bind($merchant, '-1009', 'supergroup', 'grp');

        $this->signIn();
        $this->get(route('merchant.dashboard'))->assertOk();
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
            ->assertSee('Pay-In · Today')
            ->assertSee('Pay-Out · Today')
            ->assertSee('Completed · Today')
            ->assertSee('Failed / expired · Today');
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

    /** Seed $count payments for a merchant directly (no API round trip). */
    private function seedPayments(Merchant $merchant, int $count, string $status = 'paid', string $prefix = 'ORD'): void
    {
        $number = 'PK00DEMO'.str_pad((string) $merchant->id, 16, '0', STR_PAD_LEFT);
        $account = PaymentAccount::query()->where('name', 'Account '.$number)->first() ?? $this->makeAccount($number);
        for ($i = 1; $i <= $count; $i++) {
            $id = $prefix.'-'.$merchant->id.'-'.$i.'-'.uniqid();
            $p = $merchant->payments()->create([
                'payment_id' => 'pay_'.$id, 'transaction_id' => 'TXN'.$id, 'payment_account_id' => $account->id,
                'order_id' => $prefix.'-'.$i, 'amount' => 100, 'currency' => 'PKR', 'fee_amount' => 2, 'net_amount' => 98,
                'status' => $status, 'qr_payload' => 'x', 'idempotency_key' => 'k-'.$id, 'request_hash' => hash('sha256', $id),
                'expires_at' => now()->addMinutes(5),
            ]);
            if ($status === 'paid') {
                $p->forceFill(['paid_at' => now()])->save();
            }
        }
    }

    public function test_the_payments_page_lists_every_payment_with_pagination(): void
    {
        [$merchant] = $this->portalMerchant();
        $this->seedPayments($merchant, 30);
        $this->signIn();

        $this->get(route('merchant.payments'))
            ->assertOk()
            ->assertSee('Net received')
            ->assertSee('page=2', false); // 30 payments -> a second page

        $this->get(route('merchant.payments', ['page' => 2]))->assertOk()->assertSee('ORD-1');
    }

    public function test_the_payments_page_filters_and_only_shows_own_payments(): void
    {
        [$merchant] = $this->portalMerchant();
        [$other] = $this->makeMerchant(['name' => 'Other Shop']);
        $this->seedPayments($merchant, 2, 'paid', 'MINE');
        $this->seedPayments($merchant, 1, 'expired', 'GONE');
        $this->seedPayments($other, 1, 'paid', 'THEIRS');
        $this->signIn();

        $this->get(route('merchant.payments'))->assertOk()
            ->assertSee('MINE-1')->assertSee('GONE-1')->assertDontSee('THEIRS-1');

        $this->get(route('merchant.payments', ['status' => 'expired']))->assertOk()
            ->assertSee('GONE-1')->assertDontSee('MINE-1');

        $this->get(route('merchant.payments', ['q' => 'MINE-2']))->assertOk()
            ->assertSee('MINE-2')->assertDontSee('GONE-1');
    }

    public function test_the_payments_export_downloads_csv(): void
    {
        [$merchant] = $this->portalMerchant();
        $this->seedPayments($merchant, 3);
        $this->signIn();

        $response = $this->get(route('merchant.payments.export'));
        $response->assertOk()->assertDownload();
        $this->assertStringContainsString('ORD-1', $response->streamedContent());
    }

    public function test_the_dashboard_period_filter_shows_all_time_totals(): void
    {
        [$merchant] = $this->portalMerchant();
        $this->seedPayments($merchant, 4);
        // Two older payments: outside "today" but inside "all time".
        $merchant->payments()->limit(2)->get()->each(fn ($p) => $p->forceFill(['created_at' => now()->subDays(10)])->save());
        $this->signIn();

        $this->get(route('merchant.dashboard'))->assertOk()->assertSee('Orders · Today')->assertSee('View all 4');
        $this->get(route('merchant.dashboard', ['period' => 'all']))->assertOk()->assertSee('Orders · All time');
        $this->get(route('merchant.dashboard', ['period' => 'bogus']))->assertOk()->assertSee('Orders · Today');
    }

    public function test_payment_detail_shows_own_payment_only(): void
    {
        [$merchant, $secret] = $this->portalMerchant();
        [$other, $otherSecret] = $this->makeMerchant(['name' => 'Other Shop']);
        $this->makeAccount();
        $this->createPayment($merchant, $secret, ['order_id' => 'DETAIL-1'])->assertCreated();
        $this->createPayment($other, $otherSecret, ['order_id' => 'NOTMINE-1'])->assertCreated();
        $mine = $merchant->payments()->firstOrFail();
        $theirs = $other->payments()->firstOrFail();
        $this->signIn();

        $this->get(route('merchant.payments.show', $mine->payment_id))
            ->assertOk()
            ->assertSee('DETAIL-1')
            ->assertSee('Payment created')
            ->assertSee('Net to you')
            ->assertDontSee($mine->account->name); // the gateway account is never shown

        $this->get(route('merchant.payments.show', $theirs->payment_id))->assertNotFound();
    }

    public function test_the_merchant_can_resend_a_callback(): void
    {
        // The URL guard does a DNS lookup; this test is about resending, not the guard.
        config(['gateway.allow_private_callbacks' => true]);
        Http::fake(['shop.example.com/*' => Http::response('ok', 200)]);
        [$merchant, $secret] = $this->portalMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret, ['order_id' => 'CB-1', 'callback_url' => 'https://shop.example.com/cb'])->assertCreated();
        $payment = $merchant->payments()->firstOrFail();
        $this->signIn();

        // Still pending: nothing is sent.
        $this->post(route('merchant.payments.callback', $payment->payment_id))->assertSessionHas('error');
        $this->assertSame(0, $payment->callbackDeliveries()->count());

        $payment->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        $this->post(route('merchant.payments.callback', $payment->payment_id))
            ->assertRedirect(route('merchant.payments.show', $payment->payment_id))
            ->assertSessionHas('success');
        $this->assertSame(1, $payment->callbackDeliveries()->where('success', true)->count());
    }

    public function test_the_merchant_changes_its_own_password(): void
    {
        $this->portalMerchant();
        $this->signIn();

        $this->put(route('merchant.account.password'), [
            'current_password' => 'wrong-pass-1',
            'password' => 'newpass12345', 'password_confirmation' => 'newpass12345',
        ])->assertSessionHasErrors('current_password');

        $this->put(route('merchant.account.password'), [
            'current_password' => self::PASSWORD,
            'password' => 'newpass12345', 'password_confirmation' => 'newpass12345',
        ])->assertRedirect(route('merchant.account'))->assertSessionHas('success');

        // This session stays signed in.
        $this->get(route('merchant.dashboard'))->assertOk();

        // The new password works, the old one does not.
        $this->post(route('merchant.logout'));
        $this->signIn('demostore', self::PASSWORD)->assertSessionHasErrors();
        $this->signIn('demostore', 'newpass12345')->assertRedirect(route('merchant.dashboard'));
    }

    public function test_the_statement_closing_balance_matches_the_available_balance(): void
    {
        [$merchant] = $this->portalMerchant();
        $this->seedPayments($merchant, 3);              // 3 × net 98 = 294
        $merchant->payouts()->create(['payout_ref' => 'PO1', 'method' => 'easypaisa', 'account_number' => '03001234567',
            'amount' => 50, 'currency' => 'PKR', 'status' => 'pending']);
        $rejected = $merchant->payouts()->create(['payout_ref' => 'PO2', 'method' => 'easypaisa', 'account_number' => '03001234567',
            'amount' => 40, 'currency' => 'PKR', 'status' => 'rejected']);
        $rejected->forceFill(['processed_at' => now()])->save();
        $this->signIn();

        $available = number_format($merchant->fresh()->availableBalance(), 2); // 294 − 50 = 244.00

        $this->get(route('merchant.statement'))
            ->assertOk()
            ->assertSee('Closing balance')
            ->assertSee($available);

        $csv = $this->get(route('merchant.statement.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Payment received', $csv);
        $this->assertStringContainsString('Withdrawal returned', $csv);
        $this->assertStringContainsString('"Closing balance",,,,,'.number_format($merchant->fresh()->availableBalance(), 2, '.', ''), $csv);
    }

    public function test_dashboard_charts_bucket_payments_in_pakistan_time(): void
    {
        // 10:30 UTC = 3:30 PM in Pakistan.
        $this->travelTo(Carbon::parse('2026-11-17 10:30:00', 'UTC'));
        [$merchant] = $this->portalMerchant();
        $at = function (string $order, string $status, int $amount, $createdAt) use ($merchant) {
            $merchant->payments()->create($this->paymentRow($merchant, $order, $status, ['amount' => $amount, 'net_amount' => $amount]))
                ->forceFill(['created_at' => $createdAt])->save();
        };
        $at('NOW-PAID', 'paid', 1000, now());
        $at('NOW-EXP', 'expired', 1000, now());
        $at('EARLY', 'paid', 300, Carbon::parse('2026-11-16 20:00:00', 'UTC')); // 1 AM today in Pakistan
        $at('OLDER', 'paid', 500, now()->subDays(3));
        $at('OLDEST', 'paid', 2000, now()->subDays(60));
        $this->signIn();

        // Today: hourly, midnight to 11 PM.
        $today = $this->get(route('merchant.dashboard'))->assertOk()
            ->assertSee('Paid volume')->assertSee('Success rate')->assertSee('Show as table')
            ->viewData('chart');
        $this->assertSame('hour', $today['unit']);
        $this->assertCount(24, $today['buckets']); // the whole day; hours after 3 PM are still to come
        $this->assertFalse($today['buckets'][15]['future']);
        $this->assertTrue($today['buckets'][16]['future']);
        $this->assertSame([1, 1, 300.0, 100.0], $this->bucketNumbers($today['buckets'][1]));
        $this->assertSame([2, 1, 1000.0, 50.0], $this->bucketNumbers($today['buckets'][15]));
        $this->assertNull($today['buckets'][0]['rate']);
        $this->assertSame([3, 2, 1300.0], [$today['orders'], $today['paid'], $today['volume']]);

        // Last 7 days: one bucket per day, today last.
        $week = $this->get(route('merchant.dashboard', ['period' => '7d']))->assertOk()->viewData('chart');
        $this->assertSame('day', $week['unit']);
        $this->assertCount(7, $week['buckets']);
        $this->assertSame('2026-11-14', $week['buckets'][3]['key']);
        $this->assertSame(500.0, $week['buckets'][3]['volume']);
        $this->assertSame([3, 2, 1300.0, 66.7], $this->bucketNumbers($week['buckets'][6]));

        // All time: monthly from the first payment, with clean y ticks.
        $all = $this->get(route('merchant.dashboard', ['period' => 'all']))->assertOk()->viewData('chart');
        $this->assertSame('month', $all['unit']);
        $this->assertSame(['2026-09', '2026-10', '2026-11'], array_column($all['buckets'], 'key'));
        $this->assertSame([2000.0, 0.0, 1800.0], array_column($all['buckets'], 'volume'));
        $this->assertEquals([0, 1000, 2000], $all['ticks']);
        $this->assertSame(5, $all['orders']);
    }

    public function test_dashboard_charts_show_an_empty_state_without_payments(): void
    {
        $this->portalMerchant();
        $this->signIn();

        $this->get(route('merchant.dashboard'))->assertOk()
            ->assertSee('nothing to chart')
            ->assertDontSee('Show as table');
    }

    private function bucketNumbers(array $bucket): array
    {
        return [$bucket['orders'], $bucket['paid'], $bucket['volume'], $bucket['rate']];
    }
}
