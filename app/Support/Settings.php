<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-editable gateway settings (Pay-In, Pay-Out, Fees, API, Verification).
 * Values live in the `settings` table; anything not saved yet falls back to
 * the defaults below, which come from config/gateway.php.
 */
class Settings
{
    private const CACHE_KEY = 'gateway.settings';

    public static function defaults(): array
    {
        return [
            'payin.enabled' => true,
            'payin.min_amount' => (float) config('gateway.min_amount'),
            'payin.max_amount' => (float) config('gateway.max_amount'),
            'payin.expiry_seconds' => (int) config('gateway.qr_expiry_seconds'),
            'payin.routing_strategy' => config('gateway.routing_strategy'),
            'payin.currency' => config('gateway.currency'),

            'payout.enabled' => false,
            'payout.min_amount' => 100.0,
            'payout.max_amount' => 100000.0,
            'payout.daily_limit' => 500000.0,
            'payout.processing' => 'manual',

            'fees.payin_percent' => 0.0,
            'fees.payin_fixed' => 0.0,
            'fees.payout_percent' => 0.0,
            'fees.payout_fixed' => 0.0,

            'api.rate_limit' => (int) config('gateway.api_rate_limit'),

            // sha256 of the verifier key; the plain key is shown once on generation.
            'verification.key_hash' => null,

            // Auto-verify a payment from a forwarded SMS (see App\Payments\SmsMatcher).
            'verification.sms_auto_verify' => true,
            'verification.sms_threshold' => 80,
        ];
    }

    public function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());

        return array_merge(self::defaults(), $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }
}
