<?php

namespace App\Jobs;

use App\Telegram\TelegramClient;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Sends one Telegram message. Dispatched with ->afterResponse() for events
 * fired during a web request, so the user's response is not delayed (the same
 * approach as SendPaymentCallback — it works without a queue worker).
 */
class SendTelegramMessage
{
    use Dispatchable;

    public function __construct(
        public readonly string $chatId,
        public readonly string $text,
    ) {}

    public function handle(TelegramClient $client): void
    {
        $client->sendMessage($this->chatId, $this->text);
    }
}
