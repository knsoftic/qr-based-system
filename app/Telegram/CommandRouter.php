<?php

namespace App\Telegram;

use App\Models\CallbackDelivery;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Support\Channels;
use App\Support\Settings;
use App\Support\SettlementService;
use Illuminate\Database\Eloquent\Model;

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
        $lines = [
            '<b>Available commands</b>',
            '/balance — your balance'.($owner instanceof User ? ' & commission this month' : ''),
            '/health — account & gateway status',
            '/txns — recent pay-in transactions',
            '/payouts — recent withdrawals',
            '/channels — payment-channel status',
            '/id — show this chat\'s ID',
            '/unlink — disconnect this chat',
        ];

        return implode("\n", $lines);
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
        $maintenance = $channels->where('maintenance', true)->count();
        $working = $channels->count() - $maintenance;

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
            $lines[] = $c['maintenance']
                ? '🛠️ '.e($c['name']).' — in maintenance'
                : '✅ '.e($c['name']).' — '.$c['active'].' active';
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
