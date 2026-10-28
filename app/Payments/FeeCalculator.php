<?php

namespace App\Payments;

use App\Models\Merchant;
use App\Support\Settings;

class FeeCalculator
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Merchant-specific fees override the global Pay-In fee settings.
     *
     * @return array{fee: string, net: string}
     */
    public function payIn(Merchant $merchant, string $amount): array
    {
        $percent = $merchant->fee_percent ?? $this->settings->get('fees.payin_percent', 0);
        $fixed = $merchant->fee_fixed ?? $this->settings->get('fees.payin_fixed', 0);

        $fee = round(((float) $amount) * ((float) $percent) / 100 + (float) $fixed, 2);
        $fee = min($fee, (float) $amount);

        return [
            'fee' => number_format($fee, 2, '.', ''),
            'net' => number_format((float) $amount - $fee, 2, '.', ''),
        ];
    }
}
