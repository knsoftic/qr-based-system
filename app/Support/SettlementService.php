<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Works out what a staff member earns for a period: a fixed monthly salary
 * plus a commission on the paid pay-in and pay-out volume of the merchants in
 * their downline (their own merchants, and a manager's agents' merchants).
 */
class SettlementService
{
    /**
     * @return array{
     *   payin_volume: float, payout_volume: float, payin_rate: float, payout_rate: float,
     *   payin_commission: float, payout_commission: float, salary: float, total: float, merchant_count: int
     * }
     */
    public function statement(User $staff, CarbonInterface $start, CarbonInterface $end): array
    {
        $merchantIds = $staff->downlineMerchantIds();

        $payinVolume = $merchantIds === [] ? 0.0 : (float) Payment::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', PaymentStatus::Paid->value)
            ->whereBetween('paid_at', [$start, $end])
            ->sum('amount');

        $payoutVolume = $merchantIds === [] ? 0.0 : (float) Payout::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', 'paid')
            ->whereBetween('processed_at', [$start, $end])
            ->sum('amount');

        $payinRate = (float) ($staff->payin_commission ?? 0);
        $payoutRate = (float) ($staff->payout_commission ?? 0);
        $salary = (float) ($staff->salary ?? 0);

        $payinCommission = round($payinVolume * $payinRate / 100, 2);
        $payoutCommission = round($payoutVolume * $payoutRate / 100, 2);

        return [
            'payin_volume' => $payinVolume,
            'payout_volume' => $payoutVolume,
            'payin_rate' => $payinRate,
            'payout_rate' => $payoutRate,
            'payin_commission' => $payinCommission,
            'payout_commission' => $payoutCommission,
            'salary' => $salary,
            'total' => round($salary + $payinCommission + $payoutCommission, 2),
            'merchant_count' => count($merchantIds),
        ];
    }
}
