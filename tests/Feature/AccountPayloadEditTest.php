<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Payments\Emv\Tlv;
use App\Payments\Raast\Iban;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class AccountPayloadEditTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    public function test_the_account_page_lists_every_decoded_field_with_its_meaning(): void
    {
        $this->actingAs($this->makeAdmin());
        $account = $this->makeP2pAccount();

        $this->get(route('admin.accounts.show', $account))
            ->assertOk()
            ->assertSee('Decoded QR data')
            ->assertSee('IBAN (receiving account)')
            ->assertSee('Every payment made with this QR is credited to this IBAN.')
            ->assertSee('Expiry date / time')
            ->assertSee('CRC checksum')
            ->assertSee('name="fields[04]"', false)
            ->assertSee(Iban::make('TMFB0000001234567890'))
            ->assertSee('Set per payment');
    }

    public function test_an_admin_changes_the_iban_and_new_payments_use_it(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $account = $this->makeP2pAccount();
        $oldIban = Iban::make('TMFB0000001234567890');
        $newIban = Iban::make('MEZN0000009876543210');

        $this->put(route('admin.accounts.payload', $account), [
            'password' => 'password',
            'fields' => ['03' => '', '04' => $newIban, '06' => ''],
        ])->assertSessionHas('success');

        $account->refresh();
        $this->assertSame($newIban, Tlv::find($account->payload_template['items'], '04'));
        $this->assertSame($newIban, Tlv::find(Tlv::parse($account->raw_payload), '04'));
        $this->assertSame(hash('sha256', $account->raw_payload), $account->payload_hash);
        $this->assertSame(['04'], $account->payload_summary['edited_tags']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.payload_edited', 'auditable_id' => $account->id]);

        $log = \App\Models\AuditLog::query()->where('action', 'account.payload_edited')->sole();
        $this->assertSame(['04' => $oldIban], $log->old_values);
        $this->assertSame(['04' => $newIban], $log->new_values);

        [$merchant, $secret] = $this->makeMerchant();
        $this->createPayment($merchant, $secret, ['amount' => 300])->assertCreated();
        $this->assertSame($newIban, Tlv::find(Tlv::parse(Payment::query()->sole()->qr_payload), '04'));
    }

    public function test_the_admin_password_is_required(): void
    {
        $this->actingAs($this->makeAdmin());
        $account = $this->makeP2pAccount();
        $before = $account->raw_payload;

        $this->put(route('admin.accounts.payload', $account), [
            'password' => 'wrong-password',
            'fields' => ['04' => Iban::make('MEZN0000009876543210')],
        ])->assertSessionHasErrors('password');

        $this->assertSame($before, $account->fresh()->raw_payload);
    }

    public function test_an_invalid_iban_is_not_saved(): void
    {
        $this->actingAs($this->makeAdmin());
        $account = $this->makeP2pAccount();
        $before = $account->raw_payload;

        $this->put(route('admin.accounts.payload', $account), [
            'password' => 'password',
            'fields' => ['04' => 'PK00MEZN0000009876543210'],
        ])->assertSessionHas('error');

        $this->assertSame($before, $account->fresh()->raw_payload);
    }

    public function test_a_field_the_form_did_not_send_is_left_alone(): void
    {
        $this->actingAs($this->makeAdmin());
        $account = $this->makeP2pAccount();
        $account->forceFill(['payload_template' => ['items' => Tlv::put($account->payload_template['items'], '03', 'Keep me')]])->save();

        $this->put(route('admin.accounts.payload', $account), [
            'password' => 'password',
            'fields' => ['04' => Iban::make('MEZN0000009876543210')],
        ])->assertSessionHas('success');

        $this->assertSame('Keep me', Tlv::find($account->fresh()->payload_template['items'], '03'));
    }

    public function test_two_accounts_cannot_end_up_with_identical_qr_data(): void
    {
        $this->actingAs($this->makeAdmin());
        $first = $this->makeP2pAccount('TMFB0000001234567890');
        $second = $this->makeP2pAccount('MEZN0000009876543210');

        $this->put(route('admin.accounts.payload', $second), [
            'password' => 'password',
            'fields' => ['04' => Tlv::find($first->payload_template['items'], '04')],
        ])->assertSessionHas('error');

        $this->assertStringContainsString('already uses', session('error'));
    }
}
