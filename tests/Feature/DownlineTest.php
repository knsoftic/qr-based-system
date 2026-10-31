<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class DownlineTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    public function test_an_agent_sees_only_their_own_downline(): void
    {
        $agent = User::factory()->staff('agent', ['downline.view'])->create();
        $this->makeMerchant(['name' => 'My Merchant', 'agent_id' => $agent->id]);
        $this->makeMerchant(['name' => 'Other Merchant']); // no agent

        $this->actingAs($agent)->get(route('admin.downline.index'))
            ->assertOk()->assertSee('My Merchant')->assertDontSee('Other Merchant');
    }

    public function test_an_agent_can_view_their_merchant_but_not_another(): void
    {
        $agent = User::factory()->staff('agent', ['downline.view'])->create();
        [$mine] = $this->makeMerchant(['agent_id' => $agent->id]);
        [$other] = $this->makeMerchant(['agent_id' => User::factory()->staff('agent')->create()->id]);

        $this->actingAs($agent)->get(route('admin.downline.show', $mine))->assertOk();
        $this->actingAs($agent)->get(route('admin.downline.show', $other))->assertForbidden();
    }

    public function test_a_manager_sees_their_agents_merchants(): void
    {
        $manager = User::factory()->staff('manager', ['downline.view'])->create();
        $agent = User::factory()->staff('agent')->create(['parent_id' => $manager->id]);
        [$m] = $this->makeMerchant(['name' => 'Agent Shop', 'agent_id' => $agent->id]);

        $this->actingAs($manager)->get(route('admin.downline.index'))->assertOk()->assertSee('Agent Shop');
        $this->actingAs($manager)->get(route('admin.downline.show', $m))->assertOk();
    }

    public function test_admin_sees_the_whole_agent_network_only(): void
    {
        $agent = User::factory()->staff('agent')->create();
        $this->makeMerchant(['name' => 'Network Shop', 'agent_id' => $agent->id]);
        $this->makeMerchant(['name' => 'No Agent Shop']); // no agent

        $this->actingAs($this->makeAdmin())->get(route('admin.downline.index'))
            ->assertOk()->assertSee('Network Shop')->assertDontSee('No Agent Shop');
    }

    public function test_downline_reports_order_stats(): void
    {
        $agent = User::factory()->staff('agent', ['downline.view'])->create();
        [$m] = $this->makeMerchant(['agent_id' => $agent->id]);
        $this->fundMerchant($m, 5000); // one paid order

        $this->actingAs($agent)->get(route('admin.downline.index'))
            ->assertOk()->assertSee('5,000');
        $this->actingAs($agent)->get(route('admin.downline.show', $m))->assertOk();
    }

    public function test_without_permission_downline_is_forbidden(): void
    {
        $this->actingAs(User::factory()->staff('agent', [])->create())
            ->get(route('admin.downline.index'))->assertForbidden();
    }
}
