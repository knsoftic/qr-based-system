<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramMessage;
use App\Models\CallbackDelivery;
use App\Models\Payment;
use App\Telegram\LinkService;
use App\Telegram\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private const SECRET = 'whsecret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123456:test-bot-token',
            'services.telegram.bot_username' => 'PKPayTestBot',
            'services.telegram.webhook_secret' => self::SECRET,
        ]);

        // Fake every outgoing HTTP call so no real Telegram request is made.
        Http::fake();
    }

    /** A minimal Telegram "message" update. */
    private function update(string $text, int $chatId = 555, string $type = 'private'): array
    {
        return [
            'update_id' => random_int(1, 999999),
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $chatId, 'type' => $type],
                'from' => ['id' => $chatId, 'username' => 'tester'],
                'text' => $text,
            ],
        ];
    }

    private function webhook(array $update, ?string $secret = null, ?string $header = null)
    {
        return $this->postJson(
            '/api/telegram/webhook/'.($secret ?? self::SECRET),
            $update,
            ['X-Telegram-Bot-Api-Secret-Token' => $header ?? self::SECRET],
        );
    }

    private function assertSentText(string $needle): void
    {
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), $needle));
    }

    public function test_webhook_rejects_a_wrong_path_secret(): void
    {
        $this->webhook($this->update('/help'), secret: 'wrong')->assertNotFound();
    }

    public function test_webhook_rejects_a_wrong_header_secret(): void
    {
        $this->webhook($this->update('/help'), header: 'wrong')->assertNotFound();
    }

    public function test_start_with_a_valid_token_links_the_merchant(): void
    {
        [$merchant] = $this->makeMerchant();
        $token = app(LinkService::class)->createToken($merchant);

        $this->webhook($this->update('/start '.$token))->assertOk();

        $this->assertSame('555', $merchant->fresh()->telegram_chat_id);
        $this->assertSentText('Connected');
    }

    public function test_start_with_a_bad_token_does_not_link(): void
    {
        [$merchant] = $this->makeMerchant();

        $this->webhook($this->update('/start nope'))->assertOk();

        $this->assertNull($merchant->fresh()->telegram_chat_id);
        $this->assertSentText('invalid or has expired');
    }

    public function test_balance_command_returns_the_merchant_balance(): void
    {
        [$merchant] = $this->makeMerchant();
        $this->fundMerchant($merchant, 4250);
        app(LinkService::class)->bind($merchant, '555', 'private', 'tester');

        $this->webhook($this->update('/balance'))->assertOk();

        $this->assertSentText('Balance');
        $this->assertSentText('4,250.00');
    }

    public function test_an_unlinked_chat_is_told_to_connect(): void
    {
        $this->webhook($this->update('/balance', chatId: 999))->assertOk();

        $this->assertSentText('not connected');
    }

    public function test_unlink_disconnects_the_chat(): void
    {
        [$merchant] = $this->makeMerchant();
        app(LinkService::class)->bind($merchant, '555', 'private', 'tester');

        $this->webhook($this->update('/unlink'))->assertOk();

        $this->assertNull($merchant->fresh()->telegram_chat_id);
        $this->assertSentText('disconnected');
    }

    public function test_a_chat_maps_to_only_one_merchant(): void
    {
        [$first] = $this->makeMerchant(['name' => 'First']);
        [$second] = $this->makeMerchant(['name' => 'Second']);
        $links = app(LinkService::class);

        $links->bind($first, '555', 'private', 'tester');
        $links->bind($second, '555', 'private', 'tester');

        $this->assertNull($first->fresh()->telegram_chat_id);
        $this->assertSame('555', $second->fresh()->telegram_chat_id);
    }

    public function test_an_approved_payment_notifies_the_linked_merchant(): void
    {
        Bus::fake();

        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret)->assertCreated();
        $payment = Payment::query()->firstOrFail();

        app(LinkService::class)->bind($merchant, '555', 'private', 'tester');

        $this->actingAs($this->makeAdmin());
        $this->post(route('admin.transactions.paid', $payment), ['reference' => 'REF1'])->assertRedirect();

        Bus::assertDispatchedAfterResponse(
            SendTelegramMessage::class,
            fn (SendTelegramMessage $job) => $job->chatId === '555' && str_contains($job->text, 'Payment received'),
        );
    }

    public function test_channel_maintenance_broadcasts_to_linked_owners(): void
    {
        Bus::fake();

        $this->makeAccount('PK00DEMO0000001234567890', ['channel' => 'easypaisa']);
        [$merchant] = $this->makeMerchant();
        app(LinkService::class)->bind($merchant, '555', 'private', 'tester');

        $this->actingAs($this->makeAdmin());
        $this->post(route('admin.accounts.channels.maintenance'), ['channel' => 'easypaisa', 'on' => 1])->assertRedirect();

        Bus::assertDispatchedAfterResponse(
            SendTelegramMessage::class,
            fn (SendTelegramMessage $job) => $job->chatId === '555' && str_contains($job->text, 'maintenance'),
        );
    }

    public function test_a_failed_callback_notifies_the_merchant(): void
    {
        [$merchant] = $this->makeMerchant();
        app(LinkService::class)->bind($merchant, '555', 'private', 'tester');

        $payment = $merchant->payments()->create([
            'payment_id' => 'pay_cbfail', 'transaction_id' => 'TXNCBFAIL', 'order_id' => 'ORD-CB',
            'amount' => 100, 'currency' => 'PKR', 'fee_amount' => 0, 'net_amount' => 100, 'status' => 'paid',
            'qr_payload' => 'x', 'idempotency_key' => 'cb-fail', 'request_hash' => hash('sha256', 'cb'),
            'expires_at' => now()->addMinutes(5),
        ]);

        $delivery = CallbackDelivery::query()->create([
            'payment_id' => $payment->id, 'url' => 'https://shop.example/cb', 'payload' => [],
            'attempt' => 1, 'error' => 'Connection timed out', 'success' => false,
        ]);

        app(TelegramNotifier::class)->callbackFailed($payment, $delivery);

        $this->assertSentText('Callback failed');
    }
}
