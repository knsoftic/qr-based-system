<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/**
 * A website that creates payments through the API. It can also sign in to
 * the merchant portal (guard "merchant") when the admin gave it a login.
 */
class Merchant extends Authenticatable
{
    // Portal logins never use "remember me".
    protected $rememberTokenName = '';

    protected $fillable = [
        'name',
        'email',
        'website',
        'callback_url',
        'fee_percent',
        'fee_fixed',
        'is_active',
        'username',
        'portal_enabled',
    ];

    protected $hidden = [
        'api_secret',
        'password',
    ];

    protected function casts(): array
    {
        return [
            'api_secret' => 'encrypted',
            'password' => 'hashed',
            'fee_percent' => 'decimal:2',
            'fee_fixed' => 'decimal:2',
            'is_active' => 'boolean',
            'portal_enabled' => 'boolean',
            'session_version' => 'integer',
            'secret_rotated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Merchant $merchant) {
            $merchant->merchant_code ??= self::newMerchantCode();
        });
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

    public function hasPortalLogin(): bool
    {
        return $this->username !== null && $this->password !== null;
    }

    /**
     * Signs out every open portal session (password reset, disable, ...).
     */
    public function revokePortalSessions(): void
    {
        $this->session_version = $this->session_version + 1;
    }

    public static function newMerchantCode(): string
    {
        do {
            $code = 'MID'.Str::upper(Str::random(9));
        } while (self::query()->where('merchant_code', $code)->exists());

        return $code;
    }
}
