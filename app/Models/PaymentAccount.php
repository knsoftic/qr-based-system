<?php

namespace App\Models;

use App\Enums\DecodeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAccount extends Model
{
    protected $fillable = [
        'name',
        'provider',
        'priority',
        'daily_limit',
        'is_active',
    ];

    /**
     * The original payload and the parsed template are credentials: they are
     * encrypted at rest and never serialised.
     */
    protected $hidden = [
        'raw_payload',
        'payload_template',
        'payload_hash',
        'qr_image_path',
    ];

    protected function casts(): array
    {
        return [
            'decode_status' => DecodeStatus::class,
            'raw_payload' => 'encrypted',
            'payload_template' => 'encrypted:array',
            'payload_summary' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'daily_limit' => 'decimal:2',
            'last_used_at' => 'datetime',
            'decoded_at' => 'datetime',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Accounts that may receive new payments.
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('decode_status', DecodeStatus::Decoded->value);
    }

    public function isDecoded(): bool
    {
        return $this->decode_status === DecodeStatus::Decoded;
    }

    public function providerName(): string
    {
        if ($this->provider === \App\Payments\Providers\ProviderRegistry::AUTO) {
            return 'Auto-detect (not recognised)';
        }

        return config("gateway.providers.{$this->provider}.name", $this->provider);
    }
}
