<?php

namespace App\Telegram;

use App\Support\Settings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Resolves the Telegram bot credentials. The admin can set them from the panel
 * (Settings → Telegram), where they are stored in the settings table encrypted
 * at rest; if none are set there, they fall back to .env (config/services.php).
 * The raw token is never shown back or written to the audit log.
 */
class TelegramConfig
{
    public function __construct(private readonly Settings $settings) {}

    public function botToken(): string
    {
        return $this->decrypt((string) $this->settings->get('telegram.bot_token'))
            ?: (string) config('services.telegram.bot_token', '');
    }

    public function botUsername(): ?string
    {
        $username = (string) ($this->settings->get('telegram.bot_username')
            ?: config('services.telegram.bot_username', ''));
        $username = ltrim(trim($username), '@');

        return $username !== '' ? $username : null;
    }

    public function webhookSecret(): string
    {
        return $this->decrypt((string) $this->settings->get('telegram.webhook_secret'))
            ?: (string) config('services.telegram.webhook_secret', '');
    }

    public function configured(): bool
    {
        return $this->botToken() !== '';
    }

    /** True when the value comes from the panel (DB), not just .env. */
    public function hasDbToken(): bool
    {
        return filled($this->settings->get('telegram.bot_token'));
    }

    public function setBotToken(?string $token): void
    {
        $this->settings->set(['telegram.bot_token' => $token ? Crypt::encryptString($token) : null]);
    }

    public function setBotUsername(?string $username): void
    {
        $username = $username ? ltrim(trim($username), '@') : null;
        $this->settings->set(['telegram.bot_username' => $username ?: null]);
    }

    public function setWebhookSecret(?string $secret): void
    {
        $this->settings->set(['telegram.webhook_secret' => $secret ? Crypt::encryptString($secret) : null]);
    }

    /** Ensure a webhook secret exists (generating + storing one if needed). */
    public function ensureWebhookSecret(): string
    {
        $secret = $this->webhookSecret();
        if ($secret === '') {
            $secret = Str::random(40);
            $this->setWebhookSecret($secret);
        }

        return $secret;
    }

    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            // Tolerate a value that was stored in plain text.
            return $value;
        }
    }
}
