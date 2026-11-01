<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A group or channel the bot is a member of. Populated from webhook updates;
 * used to offer the admin a pick-list when attaching a merchant.
 */
class TelegramChat extends Model
{
    protected $fillable = [
        'chat_id',
        'type',
        'title',
        'username',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function label(): string
    {
        return ($this->title ?: $this->chat_id).' ('.$this->type.')';
    }
}
