<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private function staff(string $role, array $permissions = []): User
    {
        return User::factory()->staff($role, $permissions)->create(['is_active' => true]);
    }

    public function test_super_admin_reaches_every_area(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->get(route('admin.accounts.index'))->assertOk();
        $this->get(route('admin.merchants.index'))->assertOk();
        $this->get(route('admin.settings.edit', 'payin'))->assertOk();
        $this->get(route('admin.admins.index'))->assertOk();
    }

    public function test_a_manager_only_reaches_permitted_areas(): void
    {
        $this->actingAs($this->staff('manager', ['merchants.view', 'transactions.view']));

        $this->get(route('admin.merchants.index'))->assertOk();
        $this->get(route('admin.transactions.index'))->assertOk();

        // Not granted -> forbidden.
        $this->get(route('admin.accounts.index'))->assertForbidden();
        $this->get(route('admin.settings.edit', 'payin'))->assertForbidden();
        $this->get(route('admin.admins.index'))->assertForbidden();
        // View does not imply manage.
        $this->get(route('admin.merchants.create'))->assertForbidden();
    }

    public function test_an_agent_is_tightly_scoped(): void
    {
        $this->actingAs($this->staff('agent', ['merchants.view']));

        $this->get(route('admin.merchants.index'))->assertOk();
        $this->get(route('admin.accounts.index'))->assertForbidden();
        $this->get(route('admin.payouts.index'))->assertForbidden();
        $this->post(route('admin.admins.store'), [])->assertForbidden();
    }

    public function test_a_viewer_cannot_mutate_or_resend_callbacks(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret)->assertCreated();
        $payment = Payment::query()->firstOrFail();

        $this->actingAs($this->staff('manager', ['transactions.view']));

        $this->get(route('admin.transactions.index'))->assertOk();
        $this->post(route('admin.transactions.paid', $payment))->assertForbidden();   // needs transactions.manage
        $this->post(route('admin.transactions.callback', $payment))->assertForbidden(); // needs callbacks.resend
    }

    public function test_hasPermission_logic(): void
    {
        $this->assertTrue($this->staff('super_admin')->hasPermission('anything.at.all'));

        $agent = $this->staff('agent', ['merchants.view']);
        $this->assertTrue($agent->hasPermission('merchants.view'));
        $this->assertFalse($agent->hasPermission('merchants.manage'));
        $this->assertTrue($agent->hasAnyPermission('x', 'merchants.view'));
        $this->assertFalse($agent->hasAnyPermission('x', 'y'));
    }

    public function test_admin_creates_a_manager_with_permissions_and_pay(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.admins.store'), [
            'name' => 'Asad Manager',
            'email' => 'asad@example.com',
            'password' => 'managerpass12',
            'password_confirmation' => 'managerpass12',
            'role' => 'manager',
            'permissions' => ['merchants.view', 'transactions.view'],
            'salary' => 50000,
            'payin_commission' => 1.5,
        ])->assertRedirect()->assertSessionHas('success');

        $m = User::query()->where('email', 'asad@example.com')->firstOrFail();
        $this->assertSame('manager', $m->role);
        $this->assertEqualsCanonicalizing(['merchants.view', 'transactions.view'], $m->permissions);
        $this->assertSame('50000.00', $m->salary);
        $this->assertSame('1.50', $m->payin_commission);
    }

    public function test_super_admin_role_ignores_the_permission_list(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.admins.store'), [
            'name' => 'Boss',
            'email' => 'boss@example.com',
            'password' => 'bosspass1234',
            'password_confirmation' => 'bosspass1234',
            'role' => 'super_admin',
            'permissions' => ['merchants.view'],
        ])->assertRedirect();

        $boss = User::query()->where('email', 'boss@example.com')->firstOrFail();
        $this->assertSame([], $boss->permissions);
        $this->assertTrue($boss->hasPermission('settings.manage'));
    }

    public function test_you_cannot_change_your_own_role(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->put(route('admin.admins.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'agent',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame('super_admin', $admin->fresh()->role);
    }
}
