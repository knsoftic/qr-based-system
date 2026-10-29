<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SMS forwarded by a device.
 */
class SmsMessage extends Model
{
    protected $fillable = [
        'sms_device_id',
        'message_uuid',
        'sender',
        'body',
        'received_at',
        'sim_slot',
        'sim_name',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'received_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(SmsDevice::class, 'sms_device_id');
    }

    /**
     * First "Rs 1,500" / "PKR 1500.00" style amount in an SMS, or null.
     */
    public static function detectAmount(string $body): ?string
    {
        if (! preg_match('/(?:Rs\.?|PKR)\s*:?\s*((?:\d{1,3}(?:,\d{3})+)|\d+)(?:\.(\d{1,2}))?/i', $body, $m)) {
            return null;
        }

        $amount = str_replace(',', '', $m[1]).'.'.str_pad($m[2] ?? '00', 2, '0');

        return (float) $amount > 0 ? number_format((float) $amount, 2, '.', '') : null;
    }

    /**
     * Payments with the same amount around the time this SMS arrived: a hint
     * for manual verification only, nothing is changed automatically.
     */
    public function possibleMatches(int $limit = 3): Collection
    {
        if ($this->amount === null) {
            return new Collection;
        }

        $window = (int) app(\App\Support\Settings::class)->get('payin.expiry_seconds') + (int) config('gateway.late_verification_grace_seconds');

        return Payment::query()
            ->where('amount', $this->amount)
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Expired->value, PaymentStatus::Paid->value])
            ->whereBetween('created_at', [$this->received_at->copy()->subSeconds($window), $this->received_at->copy()->addMinutes(2)])
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
