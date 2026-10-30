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
 * Trust: only a credit SMS from an authorized sender (a short code / sender
 * ID, never a personal mobile number) can verify a payment.
 *
 * Matching: a payment carries the mobile number the customer entered at
 * checkout ("claimed_phone"). When the SMS payer number matches a payment's
 * claimed number, that payment is identified even if other orders share the
 * same amount. Without a number, it falls back to a unique amount match.
 */
class SmsMatcher
{
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

        [$mode, $list] = $this->resolve($sms, $candidates);

        if ($list->isEmpty()) {
            // The payer number did not match any order of this amount.
            $sms->forceFill(['match_score' => 40, 'auto_verified' => false])->save();

            return;
        }

        $unique = $list->count() === 1;
        $chosen = $unique ? $list->first() : ($list->firstWhere('status', PaymentStatus::Pending) ?? $list->first());
        $score = $this->score($sms, $chosen, $mode, $unique);
        $threshold = (int) $this->settings->get('verification.sms_threshold', 80);

        $canAuto = (bool) $this->settings->get('verification.sms_auto_verify', true)
            && $sms->isCredit()
            && SmsMessage::isAuthorizedSender($sms->sender)
            && $unique
            && $score >= $threshold;

        if ($canAuto && $this->verify($sms, $chosen, $score, $mode)) {
            return;
        }

        $sms->forceFill([
            'matched_payment_id' => $unique ? $chosen->id : null,
            'match_score' => $score,
            'auto_verified' => false,
        ])->save();
    }

    private function verify(SmsMessage $sms, Payment $payment, int $score, string $mode): bool
    {
        $reference = $sms->bank_ref ?: ('SMS#'.$sms->id);
        $reason = $this->reason($sms, $payment, $score, $mode);

        $verified = $this->payments->transition($payment, PaymentStatus::Paid, 'sms', [
            'reference' => $reference,
            'reason' => $reason,
            'sms_id' => $sms->id,
            'sender' => $sms->sender,
            'payer_phone' => $sms->payer_phone,
            'matched_by' => $mode,
            'score' => $score,
            'auto' => true,
        ], $sms->device?->last_ip);

        if (! $verified) {
            return false;
        }

        $payment->forceFill([
            'payer_phone' => $sms->payer_phone,
            'sms_message_id' => $sms->id,
            'verification_reason' => $reason,
        ])->save();

        $sms->forceFill([
            'matched_payment_id' => $payment->id,
            'match_score' => $score,
            'auto_verified' => true,
        ])->save();

        Audit::log('payment.sms_verified', $payment, [], [
            'sms_id' => $sms->id, 'score' => $score, 'matched_by' => $mode,
            'reference' => $reference, 'payer_phone' => $sms->payer_phone, 'sender' => $sms->sender,
        ], byAdmin: false);

        return true;
    }

    /**
     * Narrow the amount candidates using the payer number.
     *
     * @return array{0: string, 1: Collection}  ['phone'|'amount', payments]
     */
    private function resolve(SmsMessage $sms, Collection $candidates): array
    {
        if ($sms->payer_phone === null) {
            // No number to match on: fall back to amount only.
            return ['amount', $candidates];
        }

        $byPhone = $candidates->where('claimed_phone', $sms->payer_phone)->values();
        if ($byPhone->isNotEmpty()) {
            return ['phone', $byPhone];
        }

        // The payer gave a number that matches no order's claimed number, so
        // only orders where the customer entered no number can still match.
        return ['amount', $candidates->whereNull('claimed_phone')->values()];
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

    private function score(SmsMessage $sms, Payment $payment, string $mode, bool $unique): int
    {
        $grace = (int) config('gateway.late_verification_grace_seconds');
        $pending = $payment->status === PaymentStatus::Pending;
        $fresh = $sms->received_at->lte($payment->expires_at->copy()->addSeconds($grace));

        if ($mode === 'phone') {
            $score = 40 + 40 + ($unique ? 15 : 0) + ($pending ? 3 : 0) + ($fresh ? 2 : 0);
        } else {
            $score = 55 + ($unique ? 30 : 0) + ($pending ? 10 : 0) + ($fresh ? 5 : 0);
        }

        return min(100, $score);
    }

    private function reason(SmsMessage $sms, Payment $payment, int $score, string $mode): string
    {
        $amount = $payment->currency.' '.number_format((float) $payment->amount, 2);
        $sender = $sms->sender;

        if ($mode === 'phone') {
            $reason = "SMS from {$sender} reported {$amount} received from {$sms->payer_phone}, which matches the number the customer entered at checkout and the order amount.";
        } else {
            $reason = "SMS from {$sender} reported {$amount} received, matching the only open order of that amount"
                .($payment->claimed_phone ? '.' : ' (no payer number was entered at checkout).');
        }

        if ($sms->bank_ref) {
            $reason .= " Bank reference {$sms->bank_ref}.";
        }

        return $reason." Match {$score}%.";
    }
}
