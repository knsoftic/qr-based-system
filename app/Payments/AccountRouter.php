<?php

namespace App\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * Picks the backend account for a new payment, following the configured
 * routing rules. Customers never see which account was chosen.
 */
class AccountRouter
{
    public const STRATEGIES = [
        'random' => 'Random (default)',
        'round_robin' => 'Round robin (least recently used)',
        'priority' => 'Priority (lowest number first)',
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function select(string $amount): ?PaymentAccount
    {
        // Active, decoded, not in maintenance, and within its daily + monthly
        // limits for this amount. Accounts over a limit are "in maintenance".
        $accounts = PaymentAccount::query()->usable()->get()
            ->filter(fn (PaymentAccount $account) => $account->canAccept((float) $amount))
            ->values();

        if ($accounts->isEmpty()) {
            return null;
        }

        // Prefer accounts with no open payment of the same amount, so an
        // incoming bank credit can be matched to exactly one payment.
        $busy = Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->where('amount', $amount)
            ->pluck('payment_account_id')
            ->all();

        $free = $accounts->reject(fn (PaymentAccount $account) => in_array($account->id, $busy, true));
        $pool = $free->isNotEmpty() ? $free : $accounts;

        // One account -> use it; several -> pick per the routing strategy
        // (random by default), so payments spread across different accounts.
        $chosen = $this->order($pool)->first();

        if ($chosen) {
            $chosen->forceFill(['last_used_at' => now()])->save();
        }

        return $chosen;
    }

    private function order(Collection $accounts): Collection
    {
        return match ($this->settings->get('payin.routing_strategy')) {
            'priority' => $accounts->sortBy([
                ['priority', 'asc'],
                fn ($a, $b) => ($a->last_used_at?->timestamp ?? 0) <=> ($b->last_used_at?->timestamp ?? 0),
            ]),
            'random' => $accounts->shuffle(),
            default => $accounts->sortBy(fn (PaymentAccount $account) => $account->last_used_at?->getTimestampMs() ?? 0),
        };
    }
}
