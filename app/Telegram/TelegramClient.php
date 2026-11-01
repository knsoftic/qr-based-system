<?php

namespace App\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Telegram Bot API. The bot token lives only in .env
 * (this repo is public). Every call is best-effort: failures are logged, never
 * thrown, so a Telegram problem can never break a payment / callback / payout.
 */
class TelegramClient
{
    public function configured(): bool
    {
        return (string) config('services.telegram.bot_token') !== '';
    }

    public function botUsername(): ?string
    {
        $u = (string) config('services.telegram.bot_username');

        return $u !== '' ? ltrim($u, '@') : null;
    }

    /**
     * Send a message. Text uses Telegram HTML parse mode, so callers must
     * escape dynamic values (use e()).
     */
    public function sendMessage(string $chatId, string $text): bool
    {
        if (! $this->configured() || $chatId === '') {
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->asJson()
                ->post($this->endpoint('sendMessage'), [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if (! $response->successful()) {
                Log::warning('Telegram sendMessage failed', ['status' => $response->status()]);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Telegram sendMessage error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Register the webhook with Telegram. The secret is sent back by Telegram
     * in the X-Telegram-Bot-Api-Secret-Token header on every update.
     *
     * @return array{ok: bool, description?: string}
     */
    public function setWebhook(string $url, string $secretToken): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message', 'edited_message', 'channel_post', 'my_chat_member'],
            'drop_pending_updates' => true,
        ]);
    }

    /** @return array{ok: bool, description?: string} */
    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => false]);
    }

    /** @return array{ok: bool, result?: array, description?: string} */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /**
     * @return array{ok: bool, result?: mixed, description?: string}
     */
    private function call(string $method, array $params = []): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'description' => 'TELEGRAM_BOT_TOKEN is not set.'];
        }

        try {
            $response = Http::timeout(15)->asJson()->post($this->endpoint($method), $params);
            $json = $response->json();

            return is_array($json) ? $json : ['ok' => false, 'description' => 'Unexpected response.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    private function endpoint(string $method): string
    {
        $base = rtrim((string) config('services.telegram.api_url', 'https://api.telegram.org'), '/');
        $token = (string) config('services.telegram.bot_token');

        return "{$base}/bot{$token}/{$method}";
    }
}
