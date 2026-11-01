<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramMessage;
use App\Models\CallbackDelivery;
use App\Models\Payment;
use App\Models\TelegramChat;
use App\Support\Channels;
use App\Telegram\LinkService;
use App\Telegram\TelegramConfig;
use App\Telegram\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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

    /** A message arriving from a group/channel the bot is in. */
    private function groupUpdate(int $chatId, string $title, string $type = 'supergroup'): array
    {
        return [
            'update_id' => random_int(1, 999999),
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $chatId, 'type' => $type, 'title' => $title],
                'from' => ['id' => 7, 'username' => 'someone'],
                'text' => 'hello',
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

    public function test_admin_saves_the_bot_token_encrypted_from_the_panel(): void
    {
        // Remove the .env fallback so only the panel (DB) value is in play.
        config(['services.telegram.bot_token' => '', 'services.telegram.bot_username' => '']);

        $this->actingAs($this->makeAdmin());
        $this->post(route('admin.telegram.save'), [
            'bot_token' => '987654:REAL-token_value',
            'bot_username' => '@MyShopBot',
        ])->assertRedirect()->assertSessionHas('success');

        // Stored, but never in plain text.
        $raw = (string) DB::table('settings')->where('key', 'telegram.bot_token')->value('value');
        $this->assertNotEmpty($raw);
        $this->assertStringNotContainsString('987654:REAL-token_value', $raw);

        $config = app(TelegramConfig::class);
        $this->assertSame('987654:REAL-token_value', $config->botToken());
        $this->assertSame('MyShopBot', $config->botUsername());
    }

    public function test_a_panel_webhook_secret_takes_precedence_over_env(): void
    {
        app(TelegramConfig::class)->setWebhookSecret('dbsecret12345');

        // The panel secret is accepted; the .env one from setUp is now ignored.
        $this->postJson('/api/telegram/webhook/dbsecret12345', $this->update('/help'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'dbsecret12345'])->assertOk();

        $this->postJson('/api/telegram/webhook/'.self::SECRET, $this->update('/help'),
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertNotFound();
    }

    public function test_pay_command_creates_a_test_order(): void
    {
        [$merchant] = $this->makeMerchant();
        $this->makeAccount();
        app(LinkService::class)->bind($merchant, '555', 'private', 'tester');

        $this->webhook($this->update('/pay 500'))->assertOk();

        $this->assertSame(1, Payment::query()->where('merchant_id', $merchant->id)->count());
        $this->assertSentText('Test order created');
    }

    public function test_pay_command_works_without_an_ip_allowlist(): void
    {
        // Empty allowlist blocks the public API, but the bot must still work.
        [$merchant] = $this->makeMerchant(['payin_ip_allowlist' => null, 'payout_ip_allowlist' => null]);
        $this->makeAccount();
        app(LinkService::class)->bind($merchant, '555', 'private', 'tester');

        $this->webhook($this->update('/pay 300'))->assertOk();

        $this->assertSame(1, Payment::query()->where('merchant_id', $merchant->id)->count());
        $this->assertSentText('Test order created');
    }

    public function test_pay_command_rejects_a_bad_amount(): void
    {
        [$merchant] = $this->makeMerchant();
        app(LinkService::class)->bind($merchant, '555', 'private', 'tester');

        $this->webhook($this->update('/pay abc'))->assertOk();

        $this->assertSame(0, Payment::query()->where('merchant_id', $merchant->id)->count());
        $this->assertSentText('Usage');
    }

    public function test_order_command_shows_status(): void
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret, ['order_id' => 'ORD-XYZ'])->assertCreated();
        app(LinkService::class)->bind($merchant, '555', 'private', 'tester');

        $this->webhook($this->update('/order ORD-XYZ'))->assertOk();

        $this->assertSentText('ORD-XYZ');
        $this->assertSentText('Pending');
    }

    public function test_channels_command_lists_payin_and_payout(): void
    {
        [$merchant] = $this->makeMerchant();
        app(LinkService::class)->bind($merchant, '555', 'private', 'tester');
        $this->makeAccount('PK00DEMO0000001234567890', ['channel' => 'easypaisa']);

        $this->webhook($this->update('/channels'))->assertOk();

        $this->assertSentText('Pay-In');
        $this->assertSentText('easypaisa');
        $this->assertSentText('Pay-Out');
    }

    public function test_channels_without_channel_names_still_shows_payout(): void
    {
        [$merchant] = $this->makeMerchant();
        app(LinkService::class)->bind($merchant, '555', 'private', 'tester');
        $this->makeAccount(); // a usable account with no channel name

        $this->webhook($this->update('/channels'))->assertOk();

        $this->assertSentText('No channels named yet');
        $this->assertSentText('Pay-Out');
    }

    public function test_a_channel_with_all_limits_used_is_not_working(): void
    {
        $account = $this->makeAccount('PK00DEMO0000001234567890', ['channel' => 'easypaisa', 'daily_limit' => 100]);
        [$merchant] = $this->makeMerchant();

        $payment = $merchant->payments()->create([
            'payment_id' => 'pay_lim', 'transaction_id' => 'TXNLIM', 'payment_account_id' => $account->id,
            'order_id' => 'LIM-1', 'amount' => 100, 'currency' => 'PKR', 'fee_amount' => 0, 'net_amount' => 100,
            'status' => 'paid', 'qr_payload' => 'x', 'idempotency_key' => 'lim', 'request_hash' => hash('sha256', 'lim'),
            'expires_at' => now()->addMinutes(5),
        ]);
        $payment->forceFill(['paid_at' => now()])->save();

        $channel = app(Channels::class)->overview()->firstWhere('name', 'easypaisa');
        $this->assertFalse($channel['working']);
        $this->assertSame(0, $channel['usable']);
    }

    public function test_the_id_command_returns_the_chat_id(): void
    {
        $update = $this->groupUpdate(-5005, 'Merchant X');
        $update['message']['text'] = '/id';

        $this->webhook($update)->assertOk();

        $this->assertSentText('-5005');
    }

    public function test_the_bot_remembers_group_chats_it_sees(): void
    {
        $this->webhook($this->groupUpdate(-2002, 'Ops Group'))->assertOk();

        $this->assertDatabaseHas('telegram_chats', [
            'chat_id' => '-2002', 'type' => 'supergroup', 'title' => 'Ops Group',
        ]);
    }

    public function test_admin_attaches_a_telegram_group_to_a_merchant(): void
    {
        TelegramChat::query()->create([
            'chat_id' => '-1009', 'type' => 'supergroup', 'title' => 'Shop Group', 'last_seen_at' => now(),
        ]);
        [$merchant] = $this->makeMerchant();

        $this->actingAs($this->makeAdmin());
        $this->put(route('admin.merchants.update', $merchant), [
            'name' => $merchant->name,
            'telegram_chat' => '-1009',
        ])->assertRedirect()->assertSessionHas('success');

        $fresh = $merchant->fresh();
        $this->assertSame('-1009', $fresh->telegram_chat_id);
        $this->assertSame('supergroup', $fresh->telegram_chat_type);
        $this->assertSentText('connected to');
    }

    public function test_admin_can_disconnect_a_merchant_telegram(): void
    {
        [$merchant] = $this->makeMerchant();
        app(LinkService::class)->bind($merchant, '-1009', 'supergroup', null);

        $this->actingAs($this->makeAdmin());
        $this->put(route('admin.merchants.update', $merchant), [
            'name' => $merchant->name,
            'telegram_chat' => '',
        ])->assertRedirect();

        $this->assertNull($merchant->fresh()->telegram_chat_id);
    }
}
