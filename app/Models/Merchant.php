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

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /** Net earnings from paid payments (after our fees). */
    public function earnedNet(): float
    {
        return (float) $this->payments()->where('status', \App\Enums\PaymentStatus::Paid->value)->sum('net_amount');
    }

    private function payoutsSum(array $statuses): float
    {
        return (float) $this->payouts()->whereIn('status', $statuses)->sum('amount');
    }

    /** Requested but not yet paid (held while awaiting admin approval). */
    public function frozenBalance(): float
    {
        return $this->payoutsSum(['pending']);
    }

    public function paidOut(): float
    {
        return $this->payoutsSum(['paid']);
    }

    /** What the merchant can still withdraw. */
    public function availableBalance(): float
    {
        return round($this->earnedNet() - $this->frozenBalance() - $this->paidOut(), 2);
    }

    /**
     * Balance as the API and portal show it. Money is formatted as a fixed
     * two-decimal string ("4250.00"), the same convention as payment amounts.
     *
     * @return array{available: string, frozen: string, paid_out: string, earned: string, currency: string}
     */
    public function balance(): array
    {
        $money = static fn (float $v): string => number_format($v, 2, '.', '');

        return [
            'available' => $money($this->availableBalance()),
            'frozen' => $money($this->frozenBalance()),
            'paid_out' => $money($this->paidOut()),
            'earned' => $money($this->earnedNet()),
            'currency' => 'PKR',
        ];
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
