<?php

namespace App\Models\Concerns;

/**
 * Shared Telegram-link helpers for the owners the bot talks to (Merchant, User).
 * The columns (telegram_chat_id, telegram_chat_type, telegram_username,
 * telegram_linked_at) are added by migration and bound via App\Telegram\LinkService.
 */
trait HasTelegram
{
    public function isTelegramLinked(): bool
    {
        return filled($this->telegram_chat_id);
    }

    /** Human name for notifications (merchant/staff name). */
    public function telegramName(): string
    {
        return (string) ($this->name ?? 'Account');
    }
}
