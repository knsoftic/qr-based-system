<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A phone running the SMS forwarder app.
 */
class SmsDevice extends Model
{
    protected $fillable = [
        'name',
        'is_active',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'device_info' => 'array',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }

    /**
     * New device key; only its hash is stored. Returns the plain key (shown once).
     */
    public function issueKey(): string
    {
        $key = 'dk_'.Str::random(40);

        $this->key_hash = hash('sha256', $key);
        $this->key_hint = substr($key, -4);

        return $key;
    }

    public static function findByKey(string $key): ?self
    {
        return $key === '' ? null : self::query()->where('key_hash', hash('sha256', $key))->first();
    }
}
