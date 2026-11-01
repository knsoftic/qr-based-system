<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Telegram\CommandRouter;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Telegram bot configuration (Settings → Telegram). The admin sets the bot
 * token and username here; they are stored encrypted in the settings table
 * (never shown back, never in the audit log). The webhook can then be
 * registered with Telegram from the same page.
 */
class TelegramController extends Controller
{
    public function __construct(private readonly TelegramConfig $config) {}

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bot_token' => ['nullable', 'string', 'max:200', 'regex:/^\d+:[\w-]+$/'],
            'bot_username' => ['nullable', 'string', 'max:64', 'regex:/^@?\w{3,}$/'],
        ], [
            'bot_token.regex' => 'That does not look like a bot token (e.g. 123456:ABC-DEF...).',
            'bot_username.regex' => 'Enter the bot username (letters, digits and _ only).',
        ]);

        if ($request->boolean('remove_token')) {
            $this->config->setBotToken(null);
        } elseif (! empty($data['bot_token'])) {
            $this->config->setBotToken(trim($data['bot_token']));
        }

        $this->config->setBotUsername($data['bot_username'] ?? null);

        Audit::log('telegram.settings_saved', null, [], [
            'token' => $request->boolean('remove_token') ? 'removed' : (! empty($data['bot_token']) ? 'updated' : 'unchanged'),
            'bot_username' => $this->config->botUsername(),
        ]);

        return $this->back('success', 'Telegram settings saved.');
    }

    public function setWebhook(TelegramClient $client): RedirectResponse
    {
        if (! $this->config->configured()) {
            return $this->back('error', 'Add the bot token first.');
        }

        $secret = $this->config->ensureWebhookSecret();
        $url = url('/api/telegram/webhook/'.$secret);
        $result = $client->setWebhook($url, $secret);
        $ok = (bool) ($result['ok'] ?? false);

        // Also register the "/" command menu so clients show it.
        if ($ok) {
            $client->setMyCommands(CommandRouter::menu());
        }

        Audit::log('telegram.webhook_set', null, [], ['ok' => $ok]);

        return $this->back($ok ? 'success' : 'error', $ok
            ? 'Webhook registered and the command menu set.'
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
