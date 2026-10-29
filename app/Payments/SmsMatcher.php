<?php

namespace App\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\SmsMessage;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * Matches a forwarded SMS to a pending payment and, when the match is strong
 * and unambiguous, verifies that payment (which fires the merchant callback).
 *
 * Score (max 100), threshold configurable (default 80):
 *   exact amount .......... 55   (required: candidates are matched on amount)
 *   single candidate ...... 30   (exactly one eligible payment at that time)
 *   target still pending .. 10
 *   SMS within validity ...  5
 *
 * Auto-verify happens ONLY when: SMS is a credit (money received), there is
 * exactly one eligible payment, the score meets the threshold, and the atomic
 * status change succeeds. Two payments with the same amount at the same time
 * are never auto-verified; they stay a hint for manual verification.
 */
class SmsMatcher
{
    private const SCORE_AMOUNT = 55;
    private const SCORE_SINGLE = 30;
    private const SCORE_PENDING = 10;
    private const SCORE_FRESH = 5;

    public function __construct(
        private readonly Settings $settings,
        private readonly PaymentService $payments,
    ) {
    }

    public function process(SmsMessage $sms): void
    {
        if ($sms->amount === null) {
            return;
        }

        $candidates = $this->candidates($sms);
        if ($candidates->isEmpty()) {
            return;
        }

        $unique = $candidates->count() === 1;
        $chosen = $unique
            ? $candidates->first()
            : ($candidates->firstWhere('status', PaymentStatus::Pending) ?? $candidates->first());

        $score = $this->score($sms, $chosen, $unique);
        $threshold = (int) $this->settings->get('verification.sms_threshold', 80);

        $canAuto = (bool) $this->settings->get('verification.sms_auto_verify', true)
            && $sms->isCredit()
            && $unique
            && $score >= $threshold;

        if ($canAuto && $this->verify($sms, $chosen, $score)) {
            return;
        }

        // Not auto-verified: record the suggestion (a single candidate) for the admin.
        $sms->forceFill([
            'matched_payment_id' => $unique ? $chosen->id : null,
            'match_score' => $score,
            'auto_verified' => false,
        ])->save();
    }

    private function verify(SmsMessage $sms, Payment $payment, int $score): bool
    {
        $reference = $sms->bank_ref ?: ('SMS#'.$sms->id);

        $verified = $this->payments->transition($payment, PaymentStatus::Paid, 'sms', [
            'reference' => $reference,
            'sms_id' => $sms->id,
            'sender' => $sms->sender,
            'payer_phone' => $sms->payer_phone,
            'score' => $score,
            'auto' => true,
        ], $sms->device?->last_ip);

        if (! $verified) {
            // Another SMS or an admin just verified it: fall back to a suggestion.
            return false;
        }

        $payment->forceFill([
            'payer_phone' => $sms->payer_phone,
            'sms_message_id' => $sms->id,
        ])->save();

        $sms->forceFill([
            'matched_payment_id' => $payment->id,
            'match_score' => $score,
            'auto_verified' => true,
        ])->save();

        Audit::log('payment.sms_verified', $payment, [], [
            'sms_id' => $sms->id,
            'score' => $score,
            'reference' => $reference,
            'payer_phone' => $sms->payer_phone,
            'sender' => $sms->sender,
        ], byAdmin: false);

        return true;
    }

    /**
     * Pending (or expired-within-grace) payments of the same amount, created
     * around the time the SMS arrived.
     */
    private function candidates(SmsMessage $sms): Collection
    {
        $grace = (int) config('gateway.late_verification_grace_seconds');
        $window = (int) $this->settings->get('payin.expiry_seconds') + $grace;
        $graceFloor = $sms->received_at->copy()->subSeconds($grace);

        return Payment::query()
            ->where('amount', $sms->amount)
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Expired->value])
            ->whereBetween('created_at', [
                $sms->received_at->copy()->subSeconds($window),
                $sms->received_at->copy()->addMinutes(2),
            ])
            ->get()
            ->filter(fn (Payment $p) => $p->status === PaymentStatus::Pending || $p->expires_at->gte($graceFloor))
            ->values();
    }

    private function score(SmsMessage $sms, Payment $payment, bool $unique): int
    {
        $grace = (int) config('gateway.late_verification_grace_seconds');

        $score = self::SCORE_AMOUNT;
        if ($unique) {
            $score += self::SCORE_SINGLE;
        }
        if ($payment->status === PaymentStatus::Pending) {
            $score += self::SCORE_PENDING;
        }
        if ($sms->received_at->lte($payment->expires_at->copy()->addSeconds($grace))) {
            $score += self::SCORE_FRESH;
        }

        return min(100, $score);
    }
}
