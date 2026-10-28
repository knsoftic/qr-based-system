<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'payment_id',
        'transaction_id',
        'merchant_id',
        'payment_account_id',
        'order_id',
        'amount',
        'currency',
        'fee_amount',
        'net_amount',
        'status',
        'qr_payload',
        'description',
        'customer_name',
        'customer_email',
        'customer_phone',
        'callback_url',
        'return_url',
        'metadata',
        'idempotency_key',
        'request_hash',
        'client_ip',
        'expires_at',
    ];

    protected $hidden = [
        'qr_payload',
        'request_hash',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'qr_payload' => 'encrypted',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'callback_sent_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('id');
    }

    public function callbackDeliveries(): HasMany
    {
        return $this->hasMany(CallbackDelivery::class)->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    /**
     * Pending in the database but already past its expiry time.
     */
    public function hasLapsed(): bool
    {
        return $this->isPending() && $this->expires_at->isPast();
    }

    public function remainingSeconds(): int
    {
        if (! $this->isPending()) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }

    public function checkoutUrl(): string
    {
        return route('checkout.show', $this->payment_id);
    }

    /**
     * Public representation shared by the API response and merchant callbacks.
     */
    public function toApiArray(): array
    {
        return [
            'payment_id' => $this->payment_id,
            'transaction_id' => $this->transaction_id,
            'order_id' => $this->order_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'fee' => $this->fee_amount,
            'net_amount' => $this->net_amount,
            'status' => $this->status->value,
            'checkout_url' => $this->checkoutUrl(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
