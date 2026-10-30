<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payout extends Model
{
    public const METHODS = [
        'easypaisa' => 'easypaisa',
        'jazzcash' => 'JazzCash',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'paid' => 'Paid',
        'rejected' => 'Rejected',
    ];

    protected $fillable = [
        'payout_ref',
        'merchant_id',
        'method',
        'account_name',
        'account_number',
        'amount',
        'currency',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public static function newRef(): string
    {
        do {
            $ref = 'PO'.now()->format('ymd').Str::upper(Str::random(6));
        } while (self::query()->where('payout_ref', $ref)->exists());

        return $ref;
    }

    public function toApiArray(): array
    {
        return [
            'payout_ref' => $this->payout_ref,
            'method' => $this->method,
            'account_number' => $this->account_number,
            'account_name' => $this->account_name,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'reference' => $this->reference,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
