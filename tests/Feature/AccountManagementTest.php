<?php

namespace Tests\Feature;

use App\Models\AccountMonthlyStat;
use App\Models\Payment;
use App\Models\PaymentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    public function test_an_admin_can_put_an_account_into_maintenance_and_back(): void
    {
        $this->actingAs($this->makeAdmin());
        $account = $this->makeAccount();

        $this->post(route('admin.accounts.maintenance', $account))->assertSessionHas('success');
        $this->assertTrue($account->fresh()->maintenance);
        $this->assertFalse($account->fresh()->canAccept(100));

        $this->post(route('admin.accounts.maintenance', $account))->assertSessionHas('success');
        $this->assertFalse($account->fresh()->maintenance);
    }

    public function test_deleting_an_account_keeps_its_past_transactions(): void
    {
        $this->actingAs($this->makeAdmin());
        [$merchant, $secret] = $this->makeMerchant();
        $account = $this->makeAccount();
        $this->createPayment($merchant, $secret)->assertCreated();
        $payment = Payment::query()->sole();

        $this->delete(route('admin.accounts.destroy', $account))
            ->assertRedirect(route('admin.accounts.index'))
            ->assertSessionHas('success');

        // Soft-deleted: gone from routing and lists, but the payment keeps its link.
        $this->assertSoftDeleted('payment_accounts', ['id' => $account->id]);
        $this->assertSame(0, PaymentAccount::query()->usable()->count());
        $this->assertSame($account->id, $payment->fresh()->payment_account_id);
        $this->assertSame($account->name, $payment->fresh()->account->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.deleted']);
    }

    public function test_a_deleted_account_is_not_used_for_new_payments(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $account = $this->makeAccount();
        $account->delete();

        $this->createPayment($merchant, $secret)->assertStatus(503);
    }

    public function test_the_account_page_shows_structured_stats(): void
    {
        $this->actingAs($this->makeAdmin());
        [$merchant, $secret] = $this->makeMerchant();
        $account = $this->makeAccount(attributes: ['monthly_limit' => 10000]);
        $this->createPayment($merchant, $secret, ['amount' => 1500])->assertCreated();
        Payment::query()->update(['status' => 'paid', 'paid_at' => now(), 'fee_amount' => 30, 'net_amount' => 1470]);

        $stats = $account->fresh()->stats();
        $this->assertSame(1, $stats['all']['verified']);
        $this->assertSame(1500.0, $stats['all']['received']);
        $this->assertSame(30.0, $stats['all']['fees']);
        $this->assertSame(1500.0, $stats['used_month']);

        $this->get(route('admin.accounts.show', $account))
            ->assertOk()
            ->assertSee('Account statistics')
            ->assertSee('Received this month')
            ->assertSee('Lifetime');
    }

    public function test_the_monthly_snapshot_command_records_last_month_and_resets(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $account = $this->makeAccount();

        // A payment "last month".
        $this->travelTo(now()->subMonthNoOverflow()->startOfMonth()->addDays(5));
        $this->createPayment($merchant, $secret, ['amount' => 1500])->assertCreated();
        Payment::query()->update(['status' => 'paid', 'paid_at' => now(), 'fee_amount' => 20, 'net_amount' => 1480]);
        $this->travelBack();

        $this->artisan('accounts:close-month')->assertSuccessful();

        $snap = AccountMonthlyStat::query()->sole();
        $this->assertSame($account->id, $snap->payment_account_id);
        $this->assertSame(1, $snap->verified);
        $this->assertSame('1500.00', $snap->received);

        // This month started fresh (the live counter is paid_at this month = 0).
        $this->assertSame(0.0, $account->fresh()->usedThisMonth());
    }
}
