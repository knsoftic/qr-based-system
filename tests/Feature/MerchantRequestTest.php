<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class MerchantRequestTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private function agent(array $permissions = ['requests.submit']): User
    {
        return User::factory()->staff('agent', $permissions)->create(['is_active' => true]);
    }

    private function submitData(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Agent Shop',
            'website' => 'https://agentshop.example.com',
            'username' => 'agentshop',
            'password' => 'shoppass1234',
            'payin_rate' => 2.5,
            'payout_rate' => 1.0,
            'note' => 'please approve',
        ];
    }

    public function test_an_agent_submits_a_request_stored_encrypted(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)
            ->post(route('admin.requests.store'), $this->submitData())
            ->assertRedirect(route('admin.requests.index'))
            ->assertSessionHas('success');

        $req = MerchantRequest::query()->sole();
        $this->assertSame($agent->id, $req->agent_id);
        $this->assertSame('pending', $req->status);
        $this->assertSame('agentshop', $req->username);
        $this->assertSame('shoppass1234', $req->password); // decrypts via cast

        $raw = DB::table('merchant_requests')->where('id', $req->id)->value('password');
        $this->assertNotSame('shoppass1234', $raw); // stored encrypted
    }

    public function test_without_permission_a_user_cannot_submit(): void
    {
        $this->actingAs($this->agent([]))
            ->post(route('admin.requests.store'), $this->submitData())
            ->assertForbidden();
    }

    public function test_a_reviewer_approves_and_the_merchant_login_works(): void
    {
        $agent = $this->agent();
        $this->actingAs($agent)->post(route('admin.requests.store'), $this->submitData())->assertRedirect();
        $req = MerchantRequest::query()->sole();

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.requests.approve', $req))
            ->assertRedirect()->assertSessionHas('new_secret');

        $req->refresh();
        $this->assertSame('approved', $req->status);

        $merchant = Merchant::query()->where('username', 'agentshop')->firstOrFail();
        $this->assertSame($agent->id, $merchant->agent_id);
        $this->assertSame('2.50', $merchant->fee_percent);
        $this->assertTrue($merchant->is_active);
        $this->assertSame($merchant->id, $req->merchant_id);

        // The created merchant can sign in with the requested credentials.
        $this->post(route('merchant.login.attempt'), ['username' => 'agentshop', 'password' => 'shoppass1234'])
            ->assertRedirect(route('merchant.dashboard'));
    }

    public function test_a_reviewer_rejects_without_creating_a_merchant(): void
    {
        $this->actingAs($this->agent())->post(route('admin.requests.store'), $this->submitData())->assertRedirect();
        $req = MerchantRequest::query()->sole();

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.requests.reject', $req), ['admin_note' => 'bad rates'])
            ->assertRedirect()->assertSessionHas('success');

        $req->refresh();
        $this->assertSame('rejected', $req->status);
        $this->assertSame('bad rates', $req->admin_note);
        $this->assertDatabaseCount('merchants', 0);
    }

    public function test_agents_see_only_their_own_requests(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $this->actingAs($a)->post(route('admin.requests.store'), $this->submitData(['username' => 'aaashop']))->assertRedirect();
        $this->actingAs($b)->post(route('admin.requests.store'), $this->submitData(['username' => 'bbbshop']))->assertRedirect();

        $this->actingAs($a)->get(route('admin.requests.index'))->assertOk()->assertSee('aaashop')->assertDontSee('bbbshop');
        $this->actingAs($this->makeAdmin())->get(route('admin.requests.index'))->assertOk()->assertSee('aaashop')->assertSee('bbbshop');
    }

    public function test_a_processed_request_cannot_be_approved_twice(): void
    {
        $this->actingAs($this->agent())->post(route('admin.requests.store'), $this->submitData())->assertRedirect();
        $req = MerchantRequest::query()->sole();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.requests.approve', $req))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.requests.approve', $req))->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseCount('merchants', 1);
    }

    public function test_a_requested_username_must_be_free(): void
    {
        $this->makeMerchant(['username' => 'taken']);

        $this->actingAs($this->agent())
            ->post(route('admin.requests.store'), $this->submitData(['username' => 'taken']))
            ->assertSessionHasErrors('username');

        $this->assertDatabaseCount('merchant_requests', 0);
    }
}
