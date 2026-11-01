<?php

namespace App\Telegram;

use App\Models\Merchant;
use App\Models\TelegramChat;
use App\Models\TelegramLinkToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The "Connect Telegram" handshake. The portal/admin creates a single-use
 * token and shows t.me/<bot>?start=<token>; the webhook consumes the token on
 * /start and binds the chat to its owner (a Merchant or a staff User).
 */
class LinkService
{
    private const TTL_MINUTES = 15;

    /** The owner types a chat can be bound to. */
    private const OWNERS = [Merchant::class, User::class];

    /**
     * Create (or replace) the pending link token for an owner.
     */
    public function createToken(Model $owner): string
    {
        TelegramLinkToken::query()
            ->where('linkable_type', $owner->getMorphClass())
            ->where('linkable_id', $owner->getKey())
            ->whereNull('used_at')
            ->delete();

        $token = Str::lower(Str::random(24));

        TelegramLinkToken::query()->create([
            'token' => $token,
            'linkable_type' => $owner->getMorphClass(),
            'linkable_id' => $owner->getKey(),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $token;
    }

    /** The deep link to show the user; null if the bot username is not set. */
    public function deepLink(string $token): ?string
    {
        $username = app(TelegramClient::class)->botUsername();

        return $username ? "https://t.me/{$username}?start={$token}" : null;
    }

    /**
     * Consume a /start token and bind the chat to its owner. Returns the owner,
     * or null when the token is unknown, used or expired.
     */
    public function consume(string $token, string $chatId, ?string $chatType, ?string $username): ?Model
    {
        $row = TelegramLinkToken::query()
            ->where('token', $token)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        $owner = $row?->linkable;
        if (! $owner) {
            return null;
        }

        $this->bind($owner, $chatId, $chatType, $username);
        $row->forceFill(['used_at' => now()])->save();

        return $owner;
    }

    /**
     * Bind a chat id to an owner. A chat maps to exactly one owner, so it is
     * first cleared from any other merchant/user that held it.
     */
    public function bind(Model $owner, string $chatId, ?string $chatType, ?string $username): void
    {
        foreach (self::OWNERS as $class) {
            $class::query()
                ->where('telegram_chat_id', $chatId)
                ->when($owner instanceof $class, fn ($q) => $q->whereKeyNot($owner->getKey()))
                ->update([
                    'telegram_chat_id' => null,
                    'telegram_chat_type' => null,
                    'telegram_username' => null,
                    'telegram_linked_at' => null,
                ]);
        }

        $owner->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_chat_type' => $chatType,
            'telegram_username' => $username ? ltrim($username, '@') : null,
            'telegram_linked_at' => now(),
        ])->save();
    }

    public function unbind(Model $owner): void
    {
        $owner->forceFill([
            'telegram_chat_id' => null,
            'telegram_chat_type' => null,
            'telegram_username' => null,
            'telegram_linked_at' => null,
        ])->save();
    }

    /** Find the owner bound to a chat id (merchant first, then staff). */
    public function ownerByChatId(string $chatId): ?Model
    {
        return Merchant::query()->where('telegram_chat_id', $chatId)->first()
            ?? User::query()->where('telegram_chat_id', $chatId)->first();
    }

    /**
     * Admin attaches (or changes / clears) a merchant's Telegram chat from the
     * merchant form. $manual (an @username or chat id) overrides $selected.
     *
     * @return array{ok: bool, message: ?string}
     */
    public function attachFromAdmin(Model $owner, ?string $selected, ?string $manual): array
    {
        $target = trim((string) ($manual ?: $selected ?: ''));

        if ($target === '') {
            if ($owner->isTelegramLinked()) {
                $this->unbind($owner);

                return ['ok' => true, 'message' => 'Telegram disconnected from this merchant.'];
            }

            return ['ok' => true, 'message' => null];
        }

        $chat = $this->resolveChat($target);
        if (! $chat) {
            return ['ok' => false, 'message' => 'Could not reach that Telegram chat. Add the bot to the group (as admin for a channel), send a message there once, then try again.'];
        }

        $chatId = (string) $chat['id'];
        if ($owner->telegram_chat_id === $chatId) {
            return ['ok' => true, 'message' => null]; // already connected to this chat
        }

        $this->bind($owner, $chatId, $chat['type'] ?? null, $chat['username'] ?? null);

        app(TelegramClient::class)->sendMessage(
            $chatId,
            '✅ This chat is now connected to <b>'.e($owner->telegramName()).'</b> for payment notifications.',
        );

        return ['ok' => true, 'message' => 'Telegram connected: '.($chat['title'] ?? $chatId).'.'];
    }

    /**
     * Resolve a chat id / @username to its details via the bot. Falls back to a
     * previously recorded chat when the lookup is unavailable.
     *
     * @return array{id: int|string, type: ?string, title: ?string, username: ?string}|null
     */
    private function resolveChat(string $target): ?array
    {
        $result = app(TelegramClient::class)->getChat($target);

        if (($result['ok'] ?? false) && isset($result['result']['id'])) {
            $r = $result['result'];

            return [
                'id' => $r['id'],
                'type' => $r['type'] ?? null,
                'title' => $r['title'] ?? ($r['username'] ?? null),
                'username' => $r['username'] ?? null,
            ];
        }

        $row = TelegramChat::query()->where('chat_id', $target)->first();
        if ($row) {
            return ['id' => $row->chat_id, 'type' => $row->type, 'title' => $row->title, 'username' => $row->username];
        }

        return null;
    }
}
