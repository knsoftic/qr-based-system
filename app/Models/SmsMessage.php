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
        'payer_phone',
        'bank_ref',
        'direction',
    ];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'received_at' => 'datetime',
            'amount' => 'decimal:2',
            'auto_verified' => 'boolean',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(SmsDevice::class, 'sms_device_id');
    }

    public function matchedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'matched_payment_id');
    }

    /**
     * Everything we read out of one SMS body, ready to store.
     *
     * @return array{amount: ?string, payer_phone: ?string, bank_ref: ?string, direction: string}
     */
    public static function parse(string $body): array
    {
        return [
            'amount' => self::detectAmount($body),
            'payer_phone' => self::detectPayerPhone($body),
            'bank_ref' => self::detectReference($body),
            'direction' => self::direction($body),
        ];
    }

    /**
     * Pakistani mobile number in the text (03xx-xxxxxxx, +923xx…), normalised to 03XXXXXXXXX.
     */
    public static function detectPayerPhone(string $body): ?string
    {
        if (! preg_match('/(?<![\d])(\+?92|0)?\s?3\d{2}[\s-]?\d{7}(?![\d])/', $body, $m)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $m[0]);
        $digits = preg_replace('/^92/', '0', $digits);
        if (strlen($digits) === 10 && $digits[0] === '3') {
            $digits = '0'.$digits;
        }

        return (strlen($digits) === 11 && str_starts_with($digits, '03')) ? $digits : null;
    }

    /**
     * Bank transaction id / reference (TID, Trx ID, Ref No, …) in the SMS.
     */
    public static function detectReference(string $body): ?string
    {
        if (preg_match('/\b(?:TID|TrxID|Trx(?:n)?\.?\s?ID|Transaction\s?(?:ID|No)|Ref(?:erence)?\.?\s?(?:No\.?|#)?)\s*[:#.]?\s*([A-Za-z0-9]{4,20})\b/i', $body, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Money direction. Only a "credit" (money received) can verify a payment.
     */
    public static function direction(string $body): string
    {
        if (preg_match('/\b(received|credited|credit|deposit(?:ed)?)\b/i', $body)) {
            return 'credit';
        }
        if (preg_match('/\b(sent|debited|debit|transferred|paid to|withdrawn|purchase)\b/i', $body)) {
            return 'debit';
        }

        return 'other';
    }

    public function isCredit(): bool
    {
        return $this->direction === 'credit';
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
