<?php

namespace Tests\Feature;

use App\Models\SmsDevice;
use App\Models\SmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class SmsForwarderTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    /**
     * @return array{0: SmsDevice, 1: string}
     */
    private function device(array $attributes = []): array
    {
        $device = new SmsDevice($attributes + ['name' => 'easypaisa phone', 'is_active' => true]);
        $key = $device->issueKey();
        $device->save();

        return [$device, $key];
    }

    private function sms(string $key, array $overrides = [])
    {
        return $this->withToken($key)->postJson('/api/v1/sms', $overrides + [
            'message_id' => 'b3f1c2d4-0001',
            'sender' => '8558',
            'body' => 'You have received Rs. 1,500.00 from MUHAMMAD ALI 0300xxxx123. TID 123456789',
            'received_at' => now()->getTimestampMs(),
            'sim_slot' => 0,
            'sim_name' => 'Telenor',
            'app_version' => '1.0',
            'device' => ['manufacturer' => 'Samsung', 'model' => 'A15', 'android' => '14'],
        ]);
    }

    public function test_the_app_stores_an_sms_with_its_full_data(): void
    {
        [$device, $key] = $this->device();

        $this->sms($key)->assertCreated()->assertJsonPath('status', 'stored')->assertJsonPath('amount', '1500.00');

        $sms = SmsMessage::query()->sole();
        $this->assertSame('8558', $sms->sender);
        $this->assertStringContainsString('MUHAMMAD ALI', $sms->body);
        $this->assertSame('1500.00', $sms->amount);
        $this->assertSame(0, $sms->sim_slot);
        $this->assertEqualsWithDelta(now()->timestamp, $sms->received_at->timestamp, 2);

        // Body is encrypted at rest.
        $this->assertStringNotContainsString('MUHAMMAD', DB::table('sms_messages')->value('body'));

        $device->refresh();
        $this->assertNotNull($device->last_seen_at);
        $this->assertSame('A15', $device->device_info['model']);
        $this->assertSame('1.0', $device->app_version);
    }

    public function test_a_retried_sms_is_not_stored_twice(): void
    {
        [, $key] = $this->device();

        $this->sms($key)->assertCreated();
        $this->sms($key)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, SmsMessage::query()->count());
    }

    public function test_a_wrong_or_disabled_key_is_rejected(): void
    {
        [$device, $key] = $this->device();

        $this->sms('dk_wrong')->assertUnauthorized();
        $this->withToken('')->postJson('/api/v1/sms', [])->assertUnauthorized();

        $device->forceFill(['is_active' => false])->save();
        $this->sms($key)->assertUnauthorized();

        $this->assertSame(0, SmsMessage::query()->count());
    }

    public function test_invalid_payloads_are_rejected(): void
    {
        [, $key] = $this->device();

        $this->sms($key, ['body' => '', 'received_at' => 'yesterday'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['body', 'received_at']]]);
    }

    public function test_ping_confirms_the_setup(): void
    {
        [$device, $key] = $this->device();

        $this->withToken($key)->postJson('/api/v1/sms/ping', ['app_version' => '1.0'])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('device', 'easypaisa phone');

        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    public function test_amounts_are_detected_in_common_formats(): void
    {
        $this->assertSame('1500.00', SmsMessage::detectAmount('Rs. 1,500.00 received'));
        $this->assertSame('100.00', SmsMessage::detectAmount('You got Rs100 from X'));
        $this->assertSame('2500.50', SmsMessage::detectAmount('PKR 2500.5 credited'));
        $this->assertSame('99.00', SmsMessage::detectAmount('Amount: PKR: 99'));
        $this->assertNull(SmsMessage::detectAmount('Your OTP is 482913'));
    }

    public function test_admin_adds_a_device_and_sees_the_key_once(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.sms.devices.store'), ['name' => 'JazzCash phone'])->assertSessionHas('new_device_key');

        $key = session('new_device_key');
        $device = SmsDevice::query()->sole();
        $this->assertSame(hash('sha256', $key), $device->key_hash);
        $this->assertStringNotContainsString($key, json_encode(DB::table('audit_logs')->get()));

        // Shown on the page right after creating it ...
        $this->get(route('admin.sms.devices'))->assertOk()->assertSee('JazzCash phone')->assertSee($key);
        // ... and never again.
        $this->get(route('admin.sms.devices'))->assertOk()->assertDontSee($key);
    }

    public function test_rotating_a_key_stops_the_old_one(): void
    {
        $this->actingAs($this->makeAdmin());
        [$device, $old] = $this->device();

        $this->post(route('admin.sms.devices.rotate', $device))->assertSessionHas('new_device_key');
        $new = session('new_device_key');

        $this->sms($old)->assertUnauthorized();
        $this->sms($new)->assertCreated();
    }

    public function test_the_inbox_shows_the_sms_and_a_possible_payment_match(): void
    {
        $this->actingAs($this->makeAdmin());
        [$merchant, $secret] = $this->makeMerchant();
        // Auto-verify off: the inbox only suggests the match, nothing is changed.
        app(\App\Support\Settings::class)->set(['verification.sms_auto_verify' => false]);
        $this->makeAccount();
        $this->createPayment($merchant, $secret, ['amount' => 1500])->assertCreated();
        $payment = \App\Models\Payment::query()->sole();

        [, $key] = $this->device();
        $this->sms($key)->assertCreated();

        $this->get(route('admin.sms.index'))
            ->assertOk()
            ->assertSee('MUHAMMAD ALI')
            ->assertSee('1,500.00')
            ->assertSee($payment->transaction_id);

        // Only a hint: the payment is not changed.
        $this->assertTrue($payment->fresh()->isPending());
    }

    public function test_the_app_download_is_admin_only(): void
    {
        $this->get(route('admin.sms.app'))->assertRedirect(route('admin.login'));
    }
}
