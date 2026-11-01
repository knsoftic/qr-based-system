<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TelegramLinkToken extends Model
{
    protected $fillable = [
        'token',
        'linkable_type',
        'linkable_id',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** The Merchant or User this token links. */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
