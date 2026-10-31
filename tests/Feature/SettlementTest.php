<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\User;
use App\Support\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class SettlementTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private function service(): SettlementService
    {
        return app(SettlementService::class);
    }

    private function paidPayout(int $merchantId, float $amount): void
    {
        $payout = Payout::query()->create([
            'payout_ref' => Payout::newRef(),
            'merchant_id' => $merchantId,
            'method' => 'easypaisa',
            'account_number' => '03001234567',
            'amount' => $amount,
            'currency' => 'PKR',
            'status' => 'paid',
        ]);
        $payout->forceFill(['processed_at' => now()])->save();
    }

    public function test_statement_computes_salary_and_commissions(): void
    {
        $agent = User::factory()->staff('agent', ['settlements.view'])->create([
            'name' => 'My Agent', 'payin_commission' => 2, 'payout_commission' => 5, 'salary' => 30000,
        ]);
        [$merchant] = $this->makeMerchant(['agent_id' => $agent->id]);

        $this->fundMerchant($merchant, 10000); // paid pay-in 10,000 this month
        $this->paidPayout($merchant->id, 2000); // paid pay-out 2,000 this month

        $s = $this->service()->statement($agent, now()->startOfMonth(), now()->endOfMonth());

        $this->assertEqualsWithDelta(10000, $s['payin_volume'], 0.01);
        $this->assertEqualsWithDelta(2000, $s['payout_volume'], 0.01);
        $this->assertEqualsWithDelta(200, $s['payin_commission'], 0.01);  // 2% of 10,000
        $this->assertEqualsWithDelta(100, $s['payout_commission'], 0.01); // 5% of 2,000
        $this->assertEqualsWithDelta(30300, $s['total'], 0.01);           // 30,000 + 200 + 100
        $this->assertSame(1, $s['merchant_count']);
    }

    public function test_volume_outside_the_month_is_excluded(): void
    {
        $agent = User::factory()->staff('agent')->create(['payin_commission' => 10]);
        [$merchant] = $this->makeMerchant(['agent_id' => $agent->id]);
        $this->fundMerchant($merchant, 5000);

        $prev = now()->subMonthNoOverflow()->startOfMonth();
        $s = $this->service()->statement($agent, $prev, $prev->copy()->endOfMonth());

        $this->assertEqualsWithDelta(0, $s['payin_volume'], 0.01);
        $this->assertEqualsWithDelta(0, $s['payin_commission'], 0.01);
    }

    public function test_a_manager_downline_includes_agents_merchants(): void
    {
        $manager = User::factory()->staff('manager')->create(['payin_commission' => 1]);
        $agent = User::factory()->staff('agent')->create(['parent_id' => $manager->id]);
        [$merchant] = $this->makeMerchant(['agent_id' => $agent->id]);
        $this->fundMerchant($merchant, 8000);

        $this->assertContains($merchant->id, $manager->downlineMerchantIds());

        $s = $this->service()->statement($manager, now()->startOfMonth(), now()->endOfMonth());
        $this->assertEqualsWithDelta(8000, $s['payin_volume'], 0.01);
        $this->assertEqualsWithDelta(80, $s['payin_commission'], 0.01);
    }

    public function test_admin_sees_all_and_a_viewer_only_self(): void
    {
        $a = User::factory()->staff('agent', ['settlements.view'])->create(['name' => 'Agent One']);
        User::factory()->staff('agent', ['settlements.view'])->create(['name' => 'Agent Two']);

        $this->actingAs($this->makeAdmin())->get(route('admin.settlements.index'))
            ->assertOk()->assertSee('Agent One')->assertSee('Agent Two');

        $this->actingAs($a)->get(route('admin.settlements.index'))
            ->assertOk()->assertSee('My earnings')->assertDontSee('Agent Two');

        $this->actingAs(User::factory()->staff('agent', [])->create())
            ->get(route('admin.settlements.index'))->assertForbidden();
    }
}
