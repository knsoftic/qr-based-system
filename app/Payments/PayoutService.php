<?php

namespace App\Payments;

use App\Models\Merchant;
use App\Models\Payout;
use App\Models\User;
use App\Payments\Exceptions\GatewayException;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Merchant withdrawals. Requesting one freezes the amount (it leaves the
 * available balance immediately); an admin later marks it paid or rejected.
 */
class PayoutService
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @param  array{method: string, account_number: string, amount: string|float, account_name?: ?string, note?: ?string}  $data
     *
     * @throws GatewayException
     */
    public function request(Merchant $merchant, array $data): Payout
    {
        if (! $this->settings->get('payout.enabled')) {
            throw new GatewayException('Pay-Out is currently disabled.', 'payout_disabled', 503);
        }
        if (! $merchant->is_active) {
            throw new GatewayException('This merchant account is disabled.', 'merchant_disabled', 403);
        }
        if (! array_key_exists($data['method'], Payout::METHODS)) {
            throw new GatewayException('Unsupported payout method.', 'invalid_method', 422);
        }

        $amount = round((float) $data['amount'], 2);
        $min = (float) $this->settings->get('payout.min_amount');
        $max = (float) $this->settings->get('payout.max_amount');

        if ($amount < $min || $amount > $max) {
            throw new GatewayException("Amount must be between {$min} and {$max} PKR.", 'amount_out_of_range', 422);
        }

        return DB::transaction(function () use ($merchant, $data, $amount) {
            // Lock the merchant row so two requests cannot both pass the balance check.
            $locked = Merchant::query()->whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            if ($amount > $locked->availableBalance()) {
                throw new GatewayException('Amount is more than your available balance.', 'insufficient_balance', 422);
            }

            return Payout::query()->create([
                'payout_ref' => Payout::newRef(),
                'merchant_id' => $locked->id,
                'method' => $data['method'],
                'account_name' => $data['account_name'] ?? null,
                'account_number' => $data['account_number'],
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => 'PKR',
                'status' => 'pending',
                'note' => $data['note'] ?? null,
            ]);
        });
    }

    /**
     * Admin confirms the money was sent. Returns false if it was not pending.
     */
    public function markPaid(Payout $payout, User $admin, ?string $reference = null, ?string $note = null): bool
    {
        return $this->settle($payout, 'paid', $admin, $reference, $note);
    }

    /**
     * Admin rejects: the amount returns to the merchant's available balance.
     */
    public function reject(Payout $payout, User $admin, ?string $note = null): bool
    {
        return $this->settle($payout, 'rejected', $admin, null, $note);
    }

    private function settle(Payout $payout, string $status, User $admin, ?string $reference, ?string $note): bool
    {
        $affected = Payout::query()
            ->whereKey($payout->getKey())
            ->where('status', 'pending')
            ->update([
                'status' => $status,
                'reference' => $reference,
                'admin_note' => $note,
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ]);

        $payout->refresh();

        return $affected > 0;
    }
}
