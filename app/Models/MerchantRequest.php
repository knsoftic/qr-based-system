<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An agent's request to create a merchant account. Approved by a reviewer,
 * which creates the merchant + portal login with these settings.
 */
class MerchantRequest extends Model
{
    public const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    protected $fillable = [
        'request_ref',
        'agent_id',
        'name',
        'website',
        'callback_url',
        'username',
        'password',
        'payin_rate',
        'payout_rate',
        'note',
        'status',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
        'merchant_id',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'payin_rate' => 'decimal:2',
            'payout_rate' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public static function newRef(): string
    {
        do {
            $ref = 'MR'.now()->format('ymd').Str::upper(Str::random(6));
        } while (self::query()->where('request_ref', $ref)->exists());

        return $ref;
    }
}
