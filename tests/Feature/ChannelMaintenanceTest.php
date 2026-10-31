<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Support\Channels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class ChannelMaintenanceTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private function channels(): Channels
    {
        return app(Channels::class);
    }

    public function test_a_channel_in_maintenance_blocks_its_accounts(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount('PK00DEMO0000000000000001', ['channel' => 'easypaisa']);

        $this->createPayment($merchant, $secret, ['order_id' => 'A'])->assertCreated();

        $this->channels()->setMaintenance('easypaisa', true);
        $this->createPayment($merchant, $secret, ['order_id' => 'B'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'no_account_available');

        $this->channels()->setMaintenance('easypaisa', false);
        $this->createPayment($merchant, $secret, ['order_id' => 'C'])->assertCreated();
    }

    public function test_other_channels_keep_working(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount('PK00DEMO0000000000000002', ['channel' => 'easypaisa']);
        $jazz = $this->makeAccount('PK00DEMO0000000000000003', ['channel' => 'JazzCash']);

        $this->channels()->setMaintenance('easypaisa', true);

        $this->createPayment($merchant, $secret, ['order_id' => 'X'])->assertCreated();
        $this->assertSame($jazz->id, Payment::query()->latest('id')->firstOrFail()->payment_account_id);
    }

    public function test_channel_maintenance_shows_in_account_state_case_insensitively(): void
    {
        $account = $this->makeAccount('PK00DEMO0000000000000004', ['channel' => 'JazzCash']);

        $this->assertTrue($account->canAccept());
        $this->assertNull($account->maintenanceReason());

        $this->channels()->setMaintenance('jazzcash', true); // different case

        $this->assertFalse($account->canAccept());
        $this->assertStringContainsString('Channel', (string) $account->maintenanceReason());
    }

    public function test_admin_can_toggle_a_channel(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->makeAccount('PK00DEMO0000000000000005', ['channel' => 'easypaisa']);

        $this->post(route('admin.accounts.channels.maintenance'), ['channel' => 'easypaisa', 'on' => 1])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertTrue($this->channels()->inMaintenance('easypaisa'));

        $this->post(route('admin.accounts.channels.maintenance'), ['channel' => 'easypaisa', 'on' => 0])
            ->assertRedirect();
        $this->assertFalse($this->channels()->inMaintenance('easypaisa'));
    }

    public function test_admin_sets_and_trims_a_channel_on_an_account(): void
    {
        $this->actingAs($this->makeAdmin());
        $account = $this->makeAccount('PK00DEMO0000000000000006', ['channel' => 'Bank']);

        $this->put(route('admin.accounts.update', $account), [
            'name' => $account->name, 'channel' => '  easypaisa  ', 'priority' => 100,
        ])->assertRedirect();

        $this->assertSame('easypaisa', $account->fresh()->channel);
    }

    public function test_channels_overview_groups_accounts_with_state(): void
    {
        $this->makeAccount('PK00DEMO0000000000000007', ['channel' => 'easypaisa']);
        $this->makeAccount('PK00DEMO0000000000000008', ['channel' => 'easypaisa']);
        $this->makeAccount('PK00DEMO0000000000000009', ['channel' => 'JazzCash']);
        $this->channels()->setMaintenance('easypaisa', true);

        $overview = $this->channels()->overview();

        $ep = $overview->firstWhere('name', 'easypaisa');
        $this->assertSame(2, $ep['total']);
        $this->assertTrue($ep['maintenance']);
        $this->assertFalse($overview->firstWhere('name', 'JazzCash')['maintenance']);
    }
}
