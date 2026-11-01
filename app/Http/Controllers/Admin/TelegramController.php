<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Telegram\TelegramClient;
use Illuminate\Http\RedirectResponse;

/**
 * Global Telegram bot configuration (Settings → Telegram). The token and
 * webhook secret live in .env; here an admin only registers / removes the
 * webhook with Telegram. The raw token is never shown or stored in the DB.
 */
class TelegramController extends Controller
{
    public function setWebhook(TelegramClient $client): RedirectResponse
    {
        if (! $client->configured()) {
            return $this->back('error', 'Set TELEGRAM_BOT_TOKEN in .env first.');
        }

        $secret = (string) config('services.telegram.webhook_secret');
        if ($secret === '') {
            return $this->back('error', 'Set TELEGRAM_WEBHOOK_SECRET in .env first (any long random string).');
        }

        $url = url('/api/telegram/webhook/'.$secret);
        $result = $client->setWebhook($url, $secret);
        $ok = (bool) ($result['ok'] ?? false);

        Audit::log('telegram.webhook_set', null, [], ['ok' => $ok]);

        return $this->back($ok ? 'success' : 'error', $ok
            ? 'Webhook registered with Telegram.'
            : 'Telegram rejected the webhook: '.($result['description'] ?? 'unknown error.'));
    }

    public function deleteWebhook(TelegramClient $client): RedirectResponse
    {
        $result = $client->deleteWebhook();
        $ok = (bool) ($result['ok'] ?? false);

        Audit::log('telegram.webhook_deleted', null, [], ['ok' => $ok]);

        return $this->back($ok ? 'success' : 'error', $ok
            ? 'Webhook removed from Telegram.'
            : 'Failed: '.($result['description'] ?? 'unknown error.'));
    }

    private function back(string $key, string $message): RedirectResponse
    {
        return redirect()->route('admin.settings.edit', 'telegram')->with($key, $message);
    }
}
