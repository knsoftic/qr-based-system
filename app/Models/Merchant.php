<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Merchant extends Model
{
    protected $fillable = [
        'name',
        'email',
        'website',
        'callback_url',
        'fee_percent',
        'fee_fixed',
        'is_active',
    ];

    protected $hidden = [
        'api_secret',
    ];

    protected function casts(): array
    {
        return [
            'api_secret' => 'encrypted',
            'fee_percent' => 'decimal:2',
            'fee_fixed' => 'decimal:2',
            'is_active' => 'boolean',
            'secret_rotated_at' => 'datetime',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Issue a fresh API key + secret pair. Returns the plain secret so it can
     * be shown to the admin exactly once.
     */
    public function issueCredentials(): string
    {
        $this->api_key = 'pk_'.Str::lower(Str::random(32));

        return $this->rotateSecret();
    }

    /**
     * Replace only the secret; the API key stays the same.
     */
    public function rotateSecret(): string
    {
        $secret = 'sk_'.Str::random(48);

        $this->api_secret = $secret;
        $this->secret_rotated_at = now();

        return $secret;
    }

    public function maskedApiKey(): string
    {
        return substr($this->api_key, 0, 7).str_repeat('•', 8).substr($this->api_key, -4);
    }
}
