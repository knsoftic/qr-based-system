<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of one payment account's totals for a calendar month, so history
 * is kept after the live monthly counter resets on the 1st.
 */
class AccountMonthlyStat extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_account_id',
        'year',
        'month',
        'attempts',
        'verified',
        'expired',
        'failed',
        'received',
        'fees',
        'net',
    ];

    protected function casts(): array
    {
        return [
            'received' => 'decimal:2',
            'fees' => 'decimal:2',
            'net' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function label(): string
    {
        return \Illuminate\Support\Carbon::create((int) $this->year, (int) $this->month)->format('M Y');
    }
}
