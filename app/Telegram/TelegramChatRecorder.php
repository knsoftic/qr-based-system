<?php

namespace App\Telegram;

use App\Models\TelegramChat;

/**
 * Remembers the groups / channels the bot is in, from webhook updates, so the
 * admin can pick one when attaching a merchant. Private chats are ignored.
 */
class TelegramChatRecorder
{
    private const CHAT_TYPES = ['group', 'supergroup', 'channel'];

    public function record(array $update): void
    {
        $chat = $this->chatFrom($update);
        $type = (string) ($chat['type'] ?? '');
        $id = isset($chat['id']) ? (string) $chat['id'] : '';

        if ($id === '' || ! in_array($type, self::CHAT_TYPES, true)) {
            return;
        }

        // The bot was removed from the chat: forget it.
        $status = $update['my_chat_member']['new_chat_member']['status'] ?? null;
        if (in_array($status, ['left', 'kicked'], true)) {
            TelegramChat::query()->where('chat_id', $id)->delete();

            return;
        }

        TelegramChat::query()->updateOrCreate(['chat_id' => $id], [
            'type' => $type,
            'title' => $chat['title'] ?? null,
            'username' => $chat['username'] ?? null,
            'last_seen_at' => now(),
        ]);
    }

    private function chatFrom(array $update): array
    {
        foreach (['message', 'edited_message', 'channel_post', 'my_chat_member'] as $key) {
            if (isset($update[$key]['chat']) && is_array($update[$key]['chat'])) {
                return $update[$key]['chat'];
            }
        }

        return [];
    }
}
