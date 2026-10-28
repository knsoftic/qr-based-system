<?php

namespace Tests\Feature;

use App\Enums\DecodeStatus;
use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAccount;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->get('/admin/accounts')->assertRedirect(route('admin.login'));
    }

    public function test_an_admin_can_sign_in_and_a_disabled_admin_cannot(): void
    {
        $admin = $this->makeAdmin();

        $this->post(route('admin.login.attempt'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login', 'user_id' => $admin->id]);

        $this->post(route('admin.logout'));
        $admin->forceFill(['is_active' => false])->save();

        $this->post(route('admin.login.attempt'), ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_every_admin_page_renders(): void
    {
        $this->actingAs($this->makeAdmin());
        [$merchant, $secret] = $this->makeMerchant();
        $account = $this->makeAccount();
        $this->createPayment($merchant, $secret)->assertCreated();
        $payment = Payment::query()->firstOrFail();

        $pages = [
            route('admin.dashboard'),
            route('admin.accounts.index'),
            route('admin.accounts.create'),
            route('admin.accounts.show', $account),
            route('admin.accounts.edit', $account),
            route('admin.merchants.index'),
            route('admin.merchants.create'),
            route('admin.merchants.show', $merchant),
            route('admin.merchants.edit', $merchant),
            route('admin.transactions.index', ['status' => 'pending']),
            route('admin.transactions.show', $payment),
            route('admin.reports'),
            route('admin.logs.audit'),
            route('admin.logs.events'),
            route('admin.logs.callbacks'),
            route('admin.admins.index'),
            route('admin.profile.edit'),
            route('docs'),
        ];

        foreach (['payin', 'payout', 'fees', 'api', 'verification'] as $tab) {
            $pages[] = route('admin.settings.edit', $tab);
        }

        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('admin.transactions.export'))->assertOk()->assertDownload();
        $this->get(route('admin.reports', ['export' => 'csv']))->assertOk()->assertDownload();
    }

    public function test_uploading_an_authorized_qr_decodes_and_saves_the_template(): void
    {
        Storage::fake('local');
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.accounts.store'), [
            'name' => 'Main account',
            'provider' => 'raast',
            'priority' => 10,
            'activate' => 1,
            'qr_image' => $this->qrUpload(DemoSeeder::samplePayload()),
        ])->assertRedirect()->assertSessionHas('success');

        $account = PaymentAccount::query()->firstOrFail();

        $this->assertSame(DecodeStatus::Decoded, $account->decode_status);
        $this->assertTrue($account->is_active);
        $this->assertSame(DemoSeeder::samplePayload(), $account->raw_payload);
        $this->assertSame('DEMO STORE', $account->payload_summary['merchant_name']);
        Storage::disk('local')->assertExists($account->qr_image_path);

        // Stored encrypted, never in plain text.
        $raw = \DB::table('payment_accounts')->value('raw_payload');
        $this->assertStringNotContainsString('PK00DEMO', $raw);

        $this->get(route('admin.accounts.show', $account))
            ->assertSee('Decoded')
            ->assertDontSee('PK00DEMO0000001234567890');

        $this->assertDatabaseHas('audit_logs', ['action' => 'account.created', 'auditable_id' => $account->id]);
    }

    public function test_a_random_qr_is_marked_unsupported_and_cannot_be_activated(): void
    {
        Storage::fake('local');
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.accounts.store'), [
            'name' => 'Random',
            'provider' => 'emvco',
            'activate' => 1,
            'qr_image' => $this->qrUpload('https://example.com/some-link'),
        ])->assertSessionHas('error');

        $account = PaymentAccount::query()->firstOrFail();
        $this->assertSame(DecodeStatus::Unsupported, $account->decode_status);
        $this->assertFalse($account->is_active);
        $this->assertNull($account->payload_template);

        $this->post(route('admin.accounts.toggle', $account))->assertSessionHas('error');
        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_auto_detect_recognises_a_raast_p2p_qr(): void
    {
        Storage::fake('local');
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.accounts.store'), [
            'name' => 'easypaisa',
            'provider' => 'auto',
            'activate' => 1,
            'qr_image' => $this->qrUpload($this->p2pPayload()),
        ])->assertSessionHas('success');

        $account = PaymentAccount::query()->firstOrFail();
        $this->assertSame('raast_p2p', $account->provider);
        $this->assertSame(DecodeStatus::Decoded, $account->decode_status);
        $this->assertTrue($account->is_active);

        $this->get(route('admin.accounts.show', $account))
            ->assertSee('Raast P2P')
            ->assertSee('TMFB')
            ->assertDontSee('1234567890');
    }

    public function test_auto_detect_picks_the_most_specific_merchant_format(): void
    {
        Storage::fake('local');
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.accounts.store'), [
            'name' => 'Shop',
            'provider' => 'auto',
            'qr_image' => $this->qrUpload(DemoSeeder::samplePayload()),
        ]);

        $this->assertSame('raast', PaymentAccount::query()->firstOrFail()->provider);
    }

    public function test_auto_detect_reports_why_every_format_rejected_a_qr(): void
    {
        Storage::fake('local');
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.accounts.store'), [
            'name' => 'Link',
            'provider' => 'auto',
            'qr_image' => $this->qrUpload('https://example.com/pay'),
        ]);

        $account = PaymentAccount::query()->firstOrFail();
        $this->assertSame(DecodeStatus::Unsupported, $account->decode_status);
        $this->assertStringContainsString('Raast P2P', $account->decode_error);
        $this->assertStringContainsString('EMVCo', $account->decode_error);
    }

    public function test_an_image_without_a_qr_fails_to_decode(): void
    {
        Storage::fake('local');
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.accounts.store'), [
            'name' => 'Blank',
            'provider' => 'emvco',
            'qr_image' => UploadedFile::fake()->image('blank.png', 200, 200),
        ]);

        $this->assertSame(DecodeStatus::Failed, PaymentAccount::query()->firstOrFail()->decode_status);
    }

    public function test_the_same_qr_cannot_be_registered_twice(): void
    {
        Storage::fake('local');
        $this->actingAs($this->makeAdmin());
        $this->makeAccount();

        $this->post(route('admin.accounts.store'), [
            'name' => 'Duplicate',
            'provider' => 'raast',
            'qr_image' => $this->qrUpload(DemoSeeder::samplePayload()),
        ]);

        $duplicate = PaymentAccount::query()->where('name', 'Duplicate')->firstOrFail();
        $this->assertSame(DecodeStatus::Unsupported, $duplicate->decode_status);
        $this->assertStringContainsString('already registered', $duplicate->decode_error);
    }

    public function test_creating_a_merchant_shows_the_secret_once_and_stores_it_encrypted(): void
    {
        $this->actingAs($this->makeAdmin());

        $response = $this->post(route('admin.merchants.store'), [
            'name' => 'New Shop',
            'website' => 'https://newshop.example.com',
            'is_active' => 1,
        ]);

        $merchant = Merchant::query()->firstOrFail();
        $secret = session('new_secret');

        $response->assertRedirect(route('admin.merchants.show', $merchant));
        $this->assertStringStartsWith('sk_', $secret);
        $this->assertSame($secret, $merchant->api_secret);
        $this->assertNotSame($secret, \DB::table('merchants')->value('api_secret'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'merchant.created']);
        $this->assertStringNotContainsString($secret, json_encode(\DB::table('audit_logs')->get()));
    }

    public function test_an_admin_can_verify_a_payment_manually(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret)->assertCreated();
        $payment = Payment::query()->firstOrFail();

        $expiresAt = $payment->expires_at->toDateTimeString();
        $this->travel(1)->minutes();

        $this->post(route('admin.transactions.paid', $payment), ['reference' => 'BANK-99'])->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        // Regression: updating a payment must never move its expiry time.
        $this->assertSame($expiresAt, $payment->expires_at->toDateTimeString());
        $this->assertSame("admin:{$admin->id}", $payment->verified_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.marked_paid', 'auditable_id' => $payment->id]);

        // A paid payment cannot be failed afterwards.
        $this->post(route('admin.transactions.failed', $payment))->assertSessionHas('error');
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_settings_are_saved_and_applied(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->put(route('admin.settings.update', 'payin'), [
            'enabled' => 1,
            'min_amount' => 10,
            'max_amount' => 5000,
            'expiry_seconds' => 120,
            'routing_strategy' => 'priority',
        ])->assertSessionHas('success');

        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();

        $this->createPayment($merchant, $secret, ['amount' => 6000])->assertStatus(422);
        $this->createPayment($merchant, $secret, ['amount' => 100])->assertCreated();

        $this->assertEqualsWithDelta(120, now()->diffInSeconds(Payment::query()->first()->expires_at), 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);
    }

    public function test_requests_without_csrf_token_are_rejected(): void
    {
        $admin = $this->makeAdmin();

        // CSRF is skipped in unit tests by default; re-enable it for this check.
        $this->withMiddleware()->actingAs($admin);
        $this->app['env'] = 'production';

        $this->post(route('admin.merchants.store'), ['name' => 'X'])->assertStatus(419);
    }
}
