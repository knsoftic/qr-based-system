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
        $accounts = PaymentAccount::query()->usable()->get();

        if ($accounts->isEmpty()) {
            return null;
        }

        $accounts = $this->withinDailyLimit($accounts, (float) $amount);

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

        $chosen = $this->order($pool)->first();

        if ($chosen) {
            $chosen->forceFill(['last_used_at' => now()])->save();
        }

        return $chosen;
    }

    private function withinDailyLimit(Collection $accounts, float $amount): Collection
    {
        $limited = $accounts->filter(fn (PaymentAccount $account) => $account->daily_limit !== null);

        if ($limited->isEmpty()) {
            return $accounts;
        }

        $usedToday = Payment::query()
            ->whereIn('payment_account_id', $limited->pluck('id'))
            ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::Pending->value])
            ->where('created_at', '>=', now()->startOfDay())
            ->groupBy('payment_account_id')
            ->selectRaw('payment_account_id, SUM(amount) as total')
            ->pluck('total', 'payment_account_id');

        return $accounts->filter(function (PaymentAccount $account) use ($usedToday, $amount) {
            if ($account->daily_limit === null) {
                return true;
            }

            return ((float) ($usedToday[$account->id] ?? 0)) + $amount <= (float) $account->daily_limit;
        })->values();
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
