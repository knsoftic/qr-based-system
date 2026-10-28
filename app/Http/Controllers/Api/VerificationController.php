<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\Exceptions\GatewayException;
use App\Payments\PaymentService;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payment verification for a trusted verifier (e.g. a bank-notification
 * forwarder). Matches by transaction ID, or by account + exact amount when
 * exactly one open payment fits.
 */
class VerificationController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly Settings $settings,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transaction_id' => ['nullable', 'string', 'max:25', 'required_without:amount'],
            'amount' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'required_without:transaction_id'],
            'account_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $payment = $this->match($data);
        $this->payments->expireIfLapsed($payment);

        if ($payment->status === PaymentStatus::Paid) {
            return response()->json(['data' => $payment->toApiArray(), 'result' => 'already_paid']);
        }

        if (isset($data['amount']) && bccomp((string) $payment->amount, number_format((float) $data['amount'], 2, '.', ''), 2) !== 0) {
            throw new GatewayException('The amount does not match the payment.', 'amount_mismatch', 409);
        }

        if (! $this->withinWindow($payment)) {
            throw new GatewayException('This payment can no longer be verified automatically. Ask an admin to review it.', 'verification_window_closed', 409);
        }

        $late = $payment->status === PaymentStatus::Expired;

        $verified = $this->payments->transition($payment, PaymentStatus::Paid, 'api', [
            'reference' => $data['reference'] ?? null,
            'late' => $late,
        ], $request->ip());

        if (! $verified) {
            throw new GatewayException('The payment status changed and cannot be verified.', 'not_verifiable', 409);
        }

        return response()->json(['data' => $payment->toApiArray(), 'result' => $late ? 'verified_late' : 'verified']);
    }

    private function match(array $data): Payment
    {
        if (! empty($data['transaction_id'])) {
            return Payment::query()->where('transaction_id', $data['transaction_id'])->first()
                ?? throw new GatewayException('No payment matches this transaction ID.', 'no_match', 404);
        }

        $window = (int) $this->settings->get('payin.expiry_seconds') + (int) config('gateway.late_verification_grace_seconds');

        $candidates = Payment::query()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Expired->value])
            ->where('amount', number_format((float) $data['amount'], 2, '.', ''))
            ->where('created_at', '>=', now()->subSeconds($window))
            ->when($data['account_id'] ?? null, fn ($query, $accountId) => $query->where('payment_account_id', $accountId))
            ->get()
            ->filter(fn (Payment $payment) => $this->withinWindow($payment));

        if ($candidates->isEmpty()) {
            throw new GatewayException('No open payment matches this amount.', 'no_match', 404);
        }

        if ($candidates->count() > 1) {
            throw new GatewayException('More than one open payment matches; send transaction_id or account_id.', 'ambiguous_match', 409);
        }

        return $candidates->first();
    }

    private function withinWindow(Payment $payment): bool
    {
        if ($payment->status === PaymentStatus::Pending) {
            return true;
        }

        return $payment->status === PaymentStatus::Expired
            && $payment->expires_at->gte(now()->subSeconds((int) config('gateway.late_verification_grace_seconds')));
    }
}
