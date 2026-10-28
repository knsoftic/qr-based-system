<?php

namespace App\Payments;

use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Payments\Exceptions\GatewayException;
use App\Payments\Providers\ProviderRegistry;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dynamic Amount -> Transaction ID -> 5 Minute Expiry -> Checkout QR ->
 * Payment Verification -> Callback / Status Update.
 */
class PaymentService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AccountRouter $router,
        private readonly ProviderRegistry $providers,
        private readonly FeeCalculator $fees,
        private readonly CallbackDispatcher $callbacks,
    ) {
    }

    /**
     * Create a payment request, or return the existing one when the same
     * Idempotency-Key is replayed with the same body.
     *
     * @return array{0: Payment, 1: bool} [payment, replayed]
     */
    public function create(Merchant $merchant, array $data, string $idempotencyKey, ?string $ip = null): array
    {
        $requestHash = hash('sha256', json_encode($this->canonical($data)));

        if ($existing = $this->findIdempotent($merchant, $idempotencyKey, $requestHash)) {
            return [$existing, true];
        }

        if (! $this->settings->get('payin.enabled')) {
            throw GatewayException::payInDisabled();
        }

        $amount = number_format((float) $data['amount'], 2, '.', '');

        try {
            $payment = DB::transaction(function () use ($merchant, $data, $amount, $idempotencyKey, $requestHash, $ip) {
                $account = $this->router->select($amount) ?? throw GatewayException::noAccountAvailable();
                $transactionId = $this->newTransactionId();
                $fees = $this->fees->payIn($merchant, $amount);

                $payment = Payment::query()->create([
                    'payment_id' => 'pay_'.Str::lower((string) Str::ulid()),
                    'transaction_id' => $transactionId,
                    'merchant_id' => $merchant->id,
                    'payment_account_id' => $account->id,
                    'order_id' => $data['order_id'],
                    'amount' => $amount,
                    'currency' => $this->settings->get('payin.currency'),
                    'fee_amount' => $fees['fee'],
                    'net_amount' => $fees['net'],
                    'status' => PaymentStatus::Pending,
                    'qr_payload' => $this->providers->make($account->provider)->build($account->payload_template, $amount, $transactionId),
                    'description' => $data['description'] ?? null,
                    'customer_name' => $data['customer']['name'] ?? null,
                    'customer_email' => $data['customer']['email'] ?? null,
                    'customer_phone' => $data['customer']['phone'] ?? null,
                    'callback_url' => $data['callback_url'] ?? null,
                    'return_url' => $data['return_url'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'client_ip' => $ip,
                    'expires_at' => now()->addSeconds((int) $this->settings->get('payin.expiry_seconds')),
                ]);

                $this->log($payment, 'created', null, PaymentStatus::Pending, 'api', [
                    'amount' => $amount,
                    'account_id' => $account->id,
                ], $ip);

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two identical requests raced: the other one won, return its result.
            if ($existing = $this->findIdempotent($merchant, $idempotencyKey, $requestHash)) {
                return [$existing, true];
            }

            throw $e;
        }

        return [$payment, false];
    }

    /**
     * Atomically move a payment to a new status and notify the merchant.
     * Returns false when the transition is not allowed or lost a race.
     */
    public function transition(Payment $payment, PaymentStatus $to, string $actor, array $data = [], ?string $ip = null): bool
    {
        $from = $payment->status;

        if (! $from->canTransitionTo($to)) {
            return false;
        }

        $updates = ['status' => $to->value, 'updated_at' => now()];

        if ($to === PaymentStatus::Paid) {
            $updates['paid_at'] = now();
            $updates['verified_by'] = $actor;
            $updates['verification_reference'] = $data['reference'] ?? null;
        }

        $affected = Payment::query()
            ->whereKey($payment->getKey())
            ->where('status', $from->value)
            ->update($updates);

        $payment->refresh();

        if ($affected === 0) {
            return false;
        }

        $this->log($payment, 'status_changed', $from, $to, $actor, $data, $ip);
        $this->callbacks->dispatch($payment);

        return true;
    }

    /**
     * Lazy expiry: called whenever a pending payment is read, so status is
     * correct even if the scheduler is not running.
     */
    public function expireIfLapsed(Payment $payment): Payment
    {
        if ($payment->hasLapsed()) {
            $this->transition($payment, PaymentStatus::Expired, 'system');
        }

        return $payment;
    }

    /**
     * Scheduled sweep of every lapsed pending payment.
     */
    public function expireLapsed(): int
    {
        $count = 0;

        Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($payments) use (&$count) {
                foreach ($payments as $payment) {
                    $count += (int) $this->transition($payment, PaymentStatus::Expired, 'system');
                }
            });

        return $count;
    }

    public function log(Payment $payment, string $event, ?PaymentStatus $from = null, ?PaymentStatus $to = null, ?string $actor = null, array $data = [], ?string $ip = null): void
    {
        PaymentEvent::query()->create([
            'payment_id' => $payment->id,
            'event' => $event,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'data' => $data ?: null,
            'actor' => $actor,
            'ip' => $ip,
        ]);
    }

    private function findIdempotent(Merchant $merchant, string $key, string $requestHash): ?Payment
    {
        $existing = Payment::query()
            ->where('merchant_id', $merchant->id)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing && ! hash_equals($existing->request_hash, $requestHash)) {
            throw GatewayException::idempotencyConflict();
        }

        return $existing ? $this->expireIfLapsed($existing) : null;
    }

    /**
     * Transaction IDs fit EMVCo's 25 character reference label:
     * TXN + yymmddHHMMSS + 6 random digits = 21 characters.
     */
    private function newTransactionId(): string
    {
        do {
            $id = 'TXN'.now()->format('ymdHis').str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Payment::query()->where('transaction_id', $id)->exists());

        return $id;
    }

    private function canonical(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonical($value);
            }
        }

        if (isset($data['amount'])) {
            $data['amount'] = number_format((float) $data['amount'], 2, '.', '');
        }

        return $data;
    }
}
