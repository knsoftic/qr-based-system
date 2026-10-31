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
 * same amount. Without a matching number, it falls back to a unique amount
 * match, so a single open order of that amount still verifies.
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
        // Among several matches, the most recent order is the active payment.
        $chosen = $this->pickLatest($list);
        $score = $this->score($sms, $chosen, $mode, $unique);
        $threshold = (int) $this->settings->get('verification.sms_threshold', 80);

        // The payer number identifies one customer, so when it matches several
        // same-amount orders we still auto-verify the latest of them. An
        // amount-only match must be unique, since different customers could
        // coincidentally share an amount.
        $resolved = $mode === 'phone' || $unique;

        $canAuto = (bool) $this->settings->get('verification.sms_auto_verify', true)
            && $sms->isCredit()
            && SmsMessage::isAuthorizedSender($sms->sender)
            && $resolved
            && $score >= $threshold;

        if ($canAuto && $this->verify($sms, $chosen, $score, $mode, ! $unique)) {
            return;
        }

        $sms->forceFill([
            'matched_payment_id' => $resolved ? $chosen->id : null,
            'match_score' => $score,
            'auto_verified' => false,
        ])->save();
    }

    private function verify(SmsMessage $sms, Payment $payment, int $score, string $mode, bool $amongMany = false): bool
    {
        $reference = $sms->bank_ref ?: ('SMS#'.$sms->id);
        $reason = $this->reason($sms, $payment, $score, $mode, $amongMany);

        // The SMS may only show a masked number; when it matched the order by
        // number, the confirmed payer is the checkout number.
        $payerPhone = $sms->payer_phone ?: ($mode === 'phone' ? $payment->claimed_phone : null);

        $verified = $this->payments->transition($payment, PaymentStatus::Paid, 'sms', [
            'reference' => $reference,
            'reason' => $reason,
            'sms_id' => $sms->id,
            'sender' => $sms->sender,
            'payer_phone' => $payerPhone,
            'matched_by' => $mode,
            'score' => $score,
            'auto' => true,
        ], $sms->device?->last_ip);

        if (! $verified) {
            return false;
        }

        $payment->forceFill([
            'payer_phone' => $payerPhone,
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
            'reference' => $reference, 'payer_phone' => $payerPhone, 'sender' => $sms->sender,
        ], byAdmin: false);

        return true;
    }

    /**
     * The order to attribute the payment to when several match: the most
     * recently created open order (a pending one over an expired one).
     */
    private function pickLatest(Collection $list): Payment
    {
        $pending = $list->where('status', PaymentStatus::Pending);
        $pool = $pending->isNotEmpty() ? $pending : $list;

        return $pool->sortByDesc('id')->first();
    }

    /**
     * Narrow the amount candidates using the payer number.
     *
     * @return array{0: string, 1: Collection}  ['phone'|'amount', payments]
     */
    private function resolve(SmsMessage $sms, Collection $candidates): array
    {
        // Match the number shown in the SMS — full OR masked (only the last few,
        // middle or leading digits visible) — against each order's checkout
        // number. A match pinpoints the order even among same-amount orders.
        $byPhone = $candidates
            ->filter(fn (Payment $p) => $p->claimed_phone && PhoneMatch::matches($p->claimed_phone, $sms->body))
            ->values();

        if ($byPhone->isNotEmpty()) {
            return ['phone', $byPhone];
        }

        // No number match: fall back to the amount. A single open order of that
        // amount still verifies — the entered number is only needed to tell
        // same-amount orders apart, and customers often pay from a different
        // number than they typed. Same-amount collisions stay ambiguous (not
        // unique) and are left for manual review.
        return ['amount', $candidates];
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

    private function reason(SmsMessage $sms, Payment $payment, int $score, string $mode, bool $amongMany = false): string
    {
        $amount = $payment->currency.' '.number_format((float) $payment->amount, 2);
        $sender = $sms->sender;

        if ($mode === 'phone') {
            $reason = "SMS from {$sender} reported {$amount} received from {$sms->payer_phone}, which matches the number the customer entered at checkout and the order amount.";
            if ($amongMany) {
                $reason .= ' Verified the most recent of several open orders from this number of that amount.';
            }
        } else {
            $reason = "SMS from {$sender} reported {$amount} received, matching the only open order of that amount.";

            if ($sms->payer_phone && $payment->claimed_phone && $sms->payer_phone !== $payment->claimed_phone) {
                $reason .= " Paid from {$sms->payer_phone}; the customer had entered {$payment->claimed_phone} at checkout.";
            } elseif (! $payment->claimed_phone) {
                $reason = rtrim($reason, '.').' (no payer number was entered at checkout).';
            }
        }

        if ($sms->bank_ref) {
            $reason .= " Bank reference {$sms->bank_ref}.";
        }

        return $reason." Match {$score}%.";
    }
}
