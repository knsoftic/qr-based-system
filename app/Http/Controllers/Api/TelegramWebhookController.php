<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Telegram\CommandRouter;
use App\Telegram\TelegramChatRecorder;
use App\Telegram\TelegramConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Telegram bot updates. Secured three ways: the {secret} path segment
 * and the X-Telegram-Bot-Api-Secret-Token header must both equal the configured
 * webhook secret, and the route is rate-limited. Always answers 200 so Telegram
 * does not retry; parsing/replying happens in the CommandRouter.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly CommandRouter $router,
        private readonly TelegramChatRecorder $recorder,
        private readonly TelegramConfig $config,
    ) {}

    public function handle(Request $request, string $secret): JsonResponse
    {
        $expected = $this->config->webhookSecret();
        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expected === '' || ! hash_equals($expected, $secret) || ! hash_equals($expected, $header)) {
            abort(404);
        }

        $update = $request->all();
        $this->recorder->record($update);
        $this->router->handle($update);

        return response()->json(['ok' => true]);
    }
}
