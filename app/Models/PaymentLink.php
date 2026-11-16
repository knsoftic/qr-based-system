<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A reusable link a merchant shares (WhatsApp, social, a printed QR). The
 * customer opens /p/{code}, taps Pay, and a fresh payment is created for the
 * fixed amount — or for the amount the customer types on an open link.
 */
class PaymentLink extends Model
{
    protected $fillable = [
        'title',
        'description',
        'amount',
        'is_active',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentLink $link) {
            $link->code ??= self::newCode();
        });
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** The customer types the amount (no fixed amount on the link). */
    public function isOpenAmount(): bool
    {
        return $this->amount === null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Can a customer pay through this link right now? */
    public function isUsable(): bool
    {
        return $this->is_active && ! $this->isExpired() && (bool) $this->merchant?->is_active;
    }

    /**
     * Public link, on the neutral short-link domain when one is configured
     * (same base as the anonymous /s/ checkout links).
     */
    public function url(): string
    {
        $base = (string) app(Settings::class)->get('checkout.short_base_url');
        $base = $base !== '' ? rtrim($base, '/') : rtrim(url('/'), '/');

        return $base.'/p/'.$this->code;
    }

    public static function newCode(): string
    {
        do {
            $code = Str::lower(Str::random(10));
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }
}
