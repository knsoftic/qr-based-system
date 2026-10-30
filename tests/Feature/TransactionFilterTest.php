<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class TransactionFilterTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    public function test_transactions_can_be_filtered_by_verification_method_and_phone(): void
    {
        $this->actingAs($this->makeAdmin());
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();

        $this->createPayment($merchant, $secret, ['amount' => 100, 'order_id' => 'BY-SMS'])->assertCreated();
        $this->createPayment($merchant, $secret, ['amount' => 200, 'order_id' => 'BY-ADMIN'])->assertCreated();

        Payment::query()->where('order_id', 'BY-SMS')->update(['status' => 'paid', 'verified_by' => 'sms', 'payer_phone' => '03001112222', 'paid_at' => now()]);
        Payment::query()->where('order_id', 'BY-ADMIN')->update(['status' => 'paid', 'verified_by' => 'admin:1', 'claimed_phone' => '03009998888', 'paid_at' => now()]);

        // Filter by method = SMS.
        $this->get(route('admin.transactions.index', ['method' => 'sms']))
            ->assertOk()->assertSee('BY-SMS')->assertDontSee('BY-ADMIN');

        // Filter by method = admin.
        $this->get(route('admin.transactions.index', ['method' => 'admin']))
            ->assertOk()->assertSee('BY-ADMIN')->assertDontSee('BY-SMS');

        // Search by payer mobile number.
        $this->get(route('admin.transactions.index', ['q' => '03001112222']))
            ->assertOk()->assertSee('BY-SMS')->assertDontSee('BY-ADMIN');

        // Search by the checkout number.
        $this->get(route('admin.transactions.index', ['q' => '9998888']))
            ->assertOk()->assertSee('BY-ADMIN')->assertDontSee('BY-SMS');
    }
}
