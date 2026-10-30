<?php

namespace Tests\Feature;

use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

/**
 * Per-merchant IP allowlisting, separate for pay-in and pay-out, strict
 * (empty list = blocked). The client IP is set with REMOTE_ADDR.
 */
class IpAllowlistApiTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private function fromIp(string $ip): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function postPayout(Merchant $merchant, string $secret): \Illuminate\Testing\TestResponse
    {
        return $this->signedJson('POST', '/api/v1/payouts', $merchant, $secret, [
            'method' => 'easypaisa', 'account_number' => '03001234567', 'amount' => 1000,
        ], ['Idempotency-Key' => 'ip-'.uniqid()]);
    }

    public function test_payin_is_blocked_from_an_ip_not_in_the_payin_list(): void
    {
        [$merchant, $secret] = $this->makeMerchant(['payin_ip_allowlist' => '203.0.113.0/24']);
        $this->makeAccount();

        $this->fromIp('198.51.100.9');
        $this->createPayment($merchant, $secret)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ip_not_allowed');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payin_is_allowed_from_an_ip_inside_the_cidr_range(): void
    {
        [$merchant, $secret] = $this->makeMerchant(['payin_ip_allowlist' => '203.0.113.0/24']);
        $this->makeAccount();

        $this->fromIp('203.0.113.77');
        $this->createPayment($merchant, $secret)->assertCreated();
    }

    public function test_an_empty_payin_list_blocks_payin(): void
    {
        [$merchant, $secret] = $this->makeMerchant(['payin_ip_allowlist' => '']);
        $this->makeAccount();

        $this->fromIp('127.0.0.1');
        $this->createPayment($merchant, $secret)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ip_not_allowed');
    }

    public function test_payout_is_blocked_from_an_ip_not_in_the_payout_list(): void
    {
        [$merchant, $secret] = $this->makeMerchant(['payout_ip_allowlist' => '203.0.113.0/24']);
        $this->fundMerchant($merchant, 5000);

        $this->fromIp('198.51.100.9');
        $this->postPayout($merchant, $secret)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ip_not_allowed');

        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_payout_is_allowed_from_an_ip_in_the_payout_list(): void
    {
        [$merchant, $secret] = $this->makeMerchant(['payout_ip_allowlist' => '198.51.100.5']);
        $this->fundMerchant($merchant, 5000);

        $this->fromIp('198.51.100.5');
        $this->postPayout($merchant, $secret)->assertCreated();
    }

    public function test_a_payin_ip_cannot_be_used_for_payouts_and_vice_versa(): void
    {
        [$merchant, $secret] = $this->makeMerchant([
            'payin_ip_allowlist' => '203.0.113.5',
            'payout_ip_allowlist' => '198.51.100.5',
        ]);
        $this->makeAccount();
        $this->fundMerchant($merchant, 5000);

        // Pay-in IP calling a payout endpoint is refused.
        $this->fromIp('203.0.113.5');
        $this->postPayout($merchant, $secret)->assertStatus(403)->assertJsonPath('error.code', 'ip_not_allowed');

        // Pay-out IP calling a payment endpoint is refused.
        $this->fromIp('198.51.100.5');
        $this->createPayment($merchant, $secret)->assertStatus(403)->assertJsonPath('error.code', 'ip_not_allowed');
    }

    public function test_balance_is_allowed_from_either_list_but_blocked_otherwise(): void
    {
        [$merchant, $secret] = $this->makeMerchant([
            'payin_ip_allowlist' => '203.0.113.5',
            'payout_ip_allowlist' => '198.51.100.5',
        ]);

        // Distinct timestamps so each request has a distinct (non-replayed) signature.
        $this->fromIp('203.0.113.5');
        $this->signedJson('GET', '/api/v1/balance', $merchant, $secret, null, [], time() - 2)->assertOk();

        $this->fromIp('198.51.100.5');
        $this->signedJson('GET', '/api/v1/balance', $merchant, $secret, null, [], time() - 1)->assertOk();

        $this->fromIp('10.0.0.1');
        $this->signedJson('GET', '/api/v1/balance', $merchant, $secret, null, [], time())
            ->assertStatus(403)->assertJsonPath('error.code', 'ip_not_allowed');
    }

    public function test_balance_is_blocked_when_both_lists_are_empty(): void
    {
        [$merchant, $secret] = $this->makeMerchant(['payin_ip_allowlist' => '', 'payout_ip_allowlist' => '']);

        $this->fromIp('127.0.0.1');
        $this->signedJson('GET', '/api/v1/balance', $merchant, $secret)
            ->assertStatus(403)->assertJsonPath('error.code', 'ip_not_allowed');
    }

    public function test_admin_validates_and_normalises_ip_allowlists(): void
    {
        $this->actingAs($this->makeAdmin());

        // An invalid entry is rejected.
        $this->post(route('admin.merchants.store'), [
            'name' => 'Bad IPs', 'is_active' => 1,
            'payin_ip_allowlist' => "203.0.113.5\nnonsense",
        ])->assertSessionHasErrors('payin_ip_allowlist');

        // Valid entries are stored normalised (one per line, de-duplicated).
        $this->post(route('admin.merchants.store'), [
            'name' => 'Good IPs', 'is_active' => 1,
            'payin_ip_allowlist' => "203.0.113.5, 203.0.113.5\n10.0.0.0/8",
            'payout_ip_allowlist' => '198.51.100.5',
        ])->assertRedirect();

        $merchant = Merchant::query()->where('name', 'Good IPs')->firstOrFail();
        $this->assertSame("203.0.113.5\n10.0.0.0/8", $merchant->payin_ip_allowlist);
        $this->assertSame('198.51.100.5', $merchant->payout_ip_allowlist);
    }
}
