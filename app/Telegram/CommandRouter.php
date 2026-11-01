<?php

namespace App\Telegram;

use App\Models\CallbackDelivery;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Payments\Exceptions\GatewayException;
use App\Payments\PaymentService;
use App\Support\Channels;
use App\Support\Settings;
use App\Support\SettlementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Parses an incoming Telegram update and replies. Commands only ever reveal
 * data for the owner (merchant / staff) bound to that chat; an unlinked chat
 * is told to connect from the portal. No secret is ever sent over Telegram.
 */
class CommandRouter
{
    private const RECENT = 5;

    public function __construct(
        private readonly LinkService $links,
        private readonly TelegramClient $client,
        private readonly Settings $settings,
        private readonly Channels $channels,
        private readonly SettlementService $settlement,
    ) {}

    public function handle(array $update): void
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (! is_array($message)) {
            return; // channel_post / my_chat_member handled elsewhere (Phase C)
        }

        $chat = $message['chat'] ?? [];
        $chatId = isset($chat['id']) ? (string) $chat['id'] : '';
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId === '' || $text === '' || $text[0] !== '/') {
            return;
        }

        [$command, $arg] = $this->parse($text);

        if ($command === 'start') {
            $this->reply($chatId, $this->handleStart($chatId, $chat, $message, $arg));

            return;
        }

        // Works in any chat (even before linking): used by the admin to copy a
        // group's / channel's id into the merchant's Telegram field.
        if ($command === 'id' || $command === 'chatid') {
            $this->reply($chatId, $this->chatIdInfo($chatId, $chat));

            return;
        }

        $owner = $this->links->ownerByChatId($chatId);
        if (! $owner) {
            $this->reply($chatId, $this->notLinked());

            return;
        }

        $reply = match ($command) {
            'help', 'menu' => $this->help($owner),
            'balance' => $this->balance($owner),
            'health' => $this->health($owner),
            'pay', 'testpay' => $this->testPay($owner, $arg),
            'order', 'status' => $this->orderStatus($owner, $arg),
            'txns', 'transactions' => $this->txns($owner),
            'payouts' => $this->payouts($owner),
            'channels' => $this->channelStatus(),
            'unlink' => $this->unlink($owner),
            default => $this->unknown(),
        };

        $this->reply($chatId, $reply);
    }

    /**
     * "/balance", "/balance@MyBot arg" -> ['balance', 'arg'].
     *
     * @return array{0: string, 1: ?string}
     */
    private function parse(string $text): array
    {
        $parts = preg_split('/\s+/', $text, 2);
        $command = ltrim(strtolower($parts[0]), '/');

        if (($at = strpos($command, '@')) !== false) {
            $command = substr($command, 0, $at);
        }

        return [$command, isset($parts[1]) ? trim($parts[1]) : null];
    }

    private function handleStart(string $chatId, array $chat, array $message, ?string $token): string
    {
        if ($token === null || $token === '') {
            $existing = $this->links->ownerByChatId($chatId);

            return $existing
                ? '✅ This chat is connected to <b>'.e($this->ownerName($existing))."</b>.\nSend /help to see what you can do."
                : '👋 Welcome. To connect your account, open the <b>Connect Telegram</b> link from your portal (merchant) or admin profile (staff).';
        }

        $username = $message['from']['username'] ?? ($chat['username'] ?? null);
        $owner = $this->links->consume($token, $chatId, $chat['type'] ?? null, $username);

        if (! $owner) {
            return '⚠️ This connect link is invalid or has expired. Open a fresh <b>Connect Telegram</b> link from your portal and try again.';
        }

        return '✅ Connected to <b>'.e($this->ownerName($owner))."</b>.\nSend /help to see what you can do.";
    }

    private function help(Model $owner): string
    {
        $lines = ['<b>Available commands</b>'];
        $lines[] = '/balance — your balance'.($owner instanceof User ? ' & commission this month' : '');
        if ($owner instanceof Merchant) {
            $lines[] = '/pay &lt;amount&gt; — create a test order (e.g. /pay 500)';
        }
        $lines[] = '/order &lt;id&gt; — order status &amp; callback';
        $lines[] = '/txns — recent pay-in transactions';
        $lines[] = '/payouts — recent withdrawals';
        $lines[] = '/channels — payment-channel status';
        $lines[] = '/health — account &amp; gateway status';
        $lines[] = '/id — show this chat\'s ID';
        $lines[] = '/unlink — disconnect this chat';

        return implode("\n", $lines);
    }

    /**
     * The command list shown as the bot's "/" menu (Telegram setMyCommands).
     *
     * @return list<array{command: string, description: string}>
     */
    public static function menu(): array
    {
        return [
            ['command' => 'balance', 'description' => 'Your balance'],
            ['command' => 'pay', 'description' => 'Create a test order, e.g. /pay 500'],
            ['command' => 'order', 'description' => 'Order status & callback, e.g. /order ORD-123'],
            ['command' => 'txns', 'description' => 'Recent pay-in transactions'],
            ['command' => 'payouts', 'description' => 'Recent withdrawals'],
            ['command' => 'channels', 'description' => 'Payment-channel status'],
            ['command' => 'health', 'description' => 'Account & gateway status'],
            ['command' => 'id', 'description' => "Show this chat's ID"],
            ['command' => 'help', 'description' => 'Show all commands'],
            ['command' => 'unlink', 'description' => 'Disconnect this chat'],
        ];
    }

    /**
     * /pay <amount> — create a real test pay-in for the merchant and reply with
     * the checkout link. The payer enters their mobile number on that page.
     */
    private function testPay(Model $owner, ?string $arg): string
    {
        if (! $owner instanceof Merchant) {
            return 'Only a merchant can create a test order.';
        }

        $amount = trim((string) $arg);
        if ($amount === '' || ! is_numeric($amount) || (float) $amount <= 0) {
            return 'Usage: <code>/pay &lt;amount&gt;</code>  e.g. <code>/pay 500</code>';
        }

        try {
            [$payment] = app(PaymentService::class)->create(
                $owner,
                [
                    'amount' => number_format((float) $amount, 2, '.', ''),
                    'order_id' => 'TG-'.now()->format('ymdHis').'-'.Str::upper(Str::random(4)),
                    'description' => 'Telegram test',
                    'metadata' => ['test' => 'true', 'source' => 'telegram'],
                ],
                'tg-'.Str::lower(Str::random(24)),
                request()?->ip(),
            );
        } catch (GatewayException $e) {
            return '⚠️ Could not create the order: '.e($e->getMessage());
        }

        $link = $payment->shortUrl() ?: $payment->checkoutUrl();

        return "🧾 <b>Test order created</b>\n"
            .'Amount: <b>'.Format::money($payment->amount, $payment->currency)."</b>\n"
            .'Order: <code>'.e($payment->order_id)."</code>\n\n"
            ."Pay / test here:\n".e($link)."\n\n"
            .'Check status: <code>/order '.e($payment->order_id).'</code>'
            ."\n<i>Real request — paying moves real money.</i>";
    }

    /**
     * /order <id> — status, amount, verification and last callback for one of
     * the owner's orders (by order id, payment id or transaction id).
     */
    private function orderStatus(Model $owner, ?string $arg): string
    {
        $id = trim((string) $arg);
        if ($id === '') {
            return 'Usage: <code>/order &lt;order id&gt;</code>';
        }

        $base = $owner instanceof Merchant
            ? $owner->payments()
            : Payment::query()->whereIn('merchant_id', $this->downlineIds($owner));

        $payment = $base
            ->where(fn ($q) => $q->where('order_id', $id)->orWhere('payment_id', $id)->orWhere('transaction_id', $id))
            ->latest('id')
            ->first();

        if (! $payment) {
            return 'No order found for <code>'.e($id).'</code>.';
        }

        app(PaymentService::class)->expireIfLapsed($payment);

        $last = $payment->callbackDeliveries()->latest('id')->first();
        $callback = $last
            ? ($last->success ? 'delivered (HTTP '.$last->response_code.')' : 'failed ('.($last->error ?: 'HTTP '.$last->response_code).')')
            : 'not sent';

        $text = '🧾 <b>Order</b> <code>'.e($payment->order_id)."</code>\n"
            .'Status: <b>'.$payment->status->label()."</b>\n"
            .'Amount: '.Format::money($payment->amount, $payment->currency)."\n"
            .($payment->paid_at ? 'Paid: '.Format::dt($payment->paid_at)."\n" : '')
            .'Callback: '.e($callback)."\n"
            .'Txn: <code>'.e($payment->transaction_id).'</code>';

        if ($payment->isPending() && $payment->shortUrl()) {
            $text .= "\nPay: ".e($payment->shortUrl());
        }

        return $text;
    }

    private function balance(Model $owner): string
    {
        if ($owner instanceof Merchant) {
            $b = $owner->balance();

            return '💰 <b>Balance</b> — '.e($owner->telegramName())."\n\n"
                .'Available: <b>'.Format::money($b['available'])."</b>\n"
                .'On hold (withdrawing): '.Format::money($b['frozen'])."\n"
                .'Paid out: '.Format::money($b['paid_out'])."\n"
                .'Total earned: '.Format::money($b['earned']);
        }

        if ($owner instanceof User) {
            $s = $this->settlement->statement($owner, now()->startOfMonth(), now()->endOfMonth());

            return '💰 <b>This month</b> — '.e($owner->telegramName()).' ('.e($owner->roleLabel()).")\n\n"
                .'Merchants: '.$s['merchant_count']."\n"
                .'Pay-in volume: '.Format::money($s['payin_volume'])."\n"
                .'Pay-out volume: '.Format::money($s['payout_volume'])."\n"
                .'Pay-in commission ('.rtrim(rtrim(number_format($s['payin_rate'], 2), '0'), '.').'%): '.Format::money($s['payin_commission'])."\n"
                .'Pay-out commission ('.rtrim(rtrim(number_format($s['payout_rate'], 2), '0'), '.').'%): '.Format::money($s['payout_commission'])."\n"
                .'Salary: '.Format::money($s['salary'])."\n"
                .'<b>Total: '.Format::money($s['total']).'</b>';
        }

        return $this->unknown();
    }

    private function health(Model $owner): string
    {
        $payinOn = (bool) $this->settings->get('payin.enabled');
        $channels = $this->channels->overview();
        $working = $channels->where('working', true)->count();
        $maintenance = $channels->count() - $working;

        if ($owner instanceof Merchant) {
            $failures = CallbackDelivery::query()
                ->whereHas('payment', fn ($q) => $q->where('merchant_id', $owner->id))
                ->where('success', false)
                ->where('created_at', '>=', now()->subDay())
                ->count();

            return '🩺 <b>Health</b> — '.e($owner->telegramName())."\n\n"
                .'Account: '.($owner->is_active ? 'Active ✅' : 'Disabled ❌')."\n"
                .'Pay-in (gateway): '.($payinOn ? 'On ✅' : 'Off ❌')."\n"
                .'Callback failures (24h): '.$failures."\n"
                .'Channels: '.$working.' working, '.$maintenance.' in maintenance';
        }

        $merchantCount = count($owner->downlineMerchantIds());

        return "🩺 <b>System health</b>\n\n"
            .'Pay-in (gateway): '.($payinOn ? 'On ✅' : 'Off ❌')."\n"
            .'Channels: '.$working.' working, '.$maintenance.' in maintenance'."\n"
            .'Your merchants: '.$merchantCount;
    }

    private function txns(Model $owner): string
    {
        $query = $owner instanceof Merchant
            ? $owner->payments()
            : Payment::query()->whereIn('merchant_id', $this->downlineIds($owner));

        $payments = $query->latest('id')->limit(self::RECENT)->get();

        if ($payments->isEmpty()) {
            return 'No pay-in transactions yet.';
        }

        $lines = ['🧾 <b>Recent pay-ins</b>'];
        foreach ($payments as $p) {
            $lines[] = '• '.Format::money($p->amount, $p->currency).' — '.$p->status->label()
                .' — <code>'.e($p->order_id).'</code> — '.Format::dt($p->created_at);
        }

        return implode("\n", $lines);
    }

    private function payouts(Model $owner): string
    {
        $query = $owner instanceof Merchant
            ? $owner->payouts()
            : Payout::query()->whereIn('merchant_id', $this->downlineIds($owner));

        $payouts = $query->latest('id')->limit(self::RECENT)->get();

        if ($payouts->isEmpty()) {
            return 'No withdrawals yet.';
        }

        $lines = ['🏧 <b>Recent withdrawals</b>'];
        foreach ($payouts as $po) {
            $lines[] = '• '.Format::money($po->amount, $po->currency).' — '.$po->statusLabel()
                .' — <code>'.e($po->payout_ref).'</code> — '.Format::dt($po->created_at);
        }

        return implode("\n", $lines);
    }

    private function channelStatus(): string
    {
        $channels = $this->channels->overview();

        if ($channels->isEmpty()) {
            return 'No payment channels configured yet.';
        }

        $lines = ['📡 <b>Payment channels</b>'];
        foreach ($channels as $c) {
            if ($c['working']) {
                $lines[] = '✅ '.e($c['name']).' — working ('.$c['usable'].'/'.$c['total'].' accounts ready)';
            } else {
                $reason = $c['maintenance'] ? 'maintenance' : 'no capacity (limits used / disabled)';
                $lines[] = '🛠️ '.e($c['name']).' — '.$reason;
            }
        }

        return implode("\n", $lines);
    }

    private function unlink(Model $owner): string
    {
        $this->links->unbind($owner);

        return "🔌 This chat has been disconnected. You will no longer receive notifications here.\nReconnect any time from your portal.";
    }

    private function chatIdInfo(string $chatId, array $chat): string
    {
        $type = (string) ($chat['type'] ?? 'chat');
        $title = $chat['title'] ?? ($chat['username'] ?? null);

        $text = "🆔 <b>Chat ID</b>\n<code>".e($chatId)."</code>\nType: ".e($type);
        if ($title) {
            $text .= "\nName: ".e($title);
        }

        return $text."\n\nCopy this ID into the merchant's Telegram field in the admin panel to connect this chat.";
    }

    private function notLinked(): string
    {
        return "This chat is not connected to any account yet.\nOpen the <b>Connect Telegram</b> link from your merchant portal (or admin profile) to link it.";
    }

    private function unknown(): string
    {
        return 'Unknown command. Send /help to see what you can do.';
    }

    /** @return list<int> */
    private function downlineIds(User $owner): array
    {
        return $owner->downlineMerchantIds();
    }

    private function ownerName(Model $owner): string
    {
        return method_exists($owner, 'telegramName') ? $owner->telegramName() : (string) ($owner->name ?? 'Account');
    }

    private function reply(string $chatId, string $text): void
    {
        $this->client->sendMessage($chatId, $text);
    }
}
