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

            'payout.enabled' => true,
            'payout.min_amount' => 100.0,
            'payout.max_amount' => 100000.0,
            'payout.daily_limit' => 500000.0,
            'payout.processing' => 'manual',

            'fees.payin_percent' => 0.0,
            'fees.payin_fixed' => 0.0,
            'fees.payout_percent' => 0.0,
            'fees.payout_fixed' => 0.0,

            'api.rate_limit' => (int) config('gateway.api_rate_limit'),

            // Payment-method channels (easypaisa, JazzCash, ...) currently in
            // maintenance. Accounts of a channel in this list stop receiving
            // new payments while other channels keep working. See App\Support\Channels.
            'channels.maintenance' => [],

            // sha256 of the verifier key; the plain key is shown once on generation.
            'verification.key_hash' => null,

            // Auto-verify a payment from a forwarded SMS (see App\Payments\SmsMatcher).
            'verification.sms_auto_verify' => true,
            'verification.sms_threshold' => 80,

            // ---- Checkout page branding & content (App\Support\CheckoutTheme) ----
            'checkout.language' => 'ur',               // ur (RTL) | en
            'checkout.font' => 'nastaliq',             // nastaliq (Noto/custom) | system
            'checkout.font_path' => null,              // uploaded custom font (public/branding)
            'checkout.logo_path' => null,              // uploaded logo (public/branding)
            'checkout.primary_color' => '#6366f1',
            'checkout.accent_color' => '#8b5cf6',

            'checkout.tagline' => 'Raast QR کے ذریعے محفوظ ادائیگی',
            'checkout.step1_title' => 'پہلا مرحلہ — وہ موبائل نمبر درج کریں جس سے آپ ادائیگی کریں گے',
            'checkout.step1_desc' => 'اُسی اکاؤنٹ کا موبائل نمبر لکھیں جس سے آپ یہ رقم بھیجیں گے۔ ہم اسی سے آپ کی ادائیگی خود بخود تصدیق کرتے ہیں۔ نمبر درج کرنے کے بعد QR کوڈ ظاہر ہوگا۔',
            'checkout.step1_button' => 'QR کوڈ دکھائیں',
            'checkout.step2_hint' => 'دوسرا مرحلہ — یہ QR اسکین کریں اور ادائیگی کریں',
            'checkout.steps' => "جس نمبر کا آپ نے اندراج کیا اُس پر اپنی بینکنگ یا والٹ ایپ کھولیں۔\nیہ QR کوڈ اسکین کریں۔ رقم خود بخود بھر جائے گی۔\nادائیگی کی تصدیق کریں۔ یہ صفحہ خود اپ ڈیٹ ہو جائے گا۔",
            'checkout.paid_title' => 'ادائیگی موصول ہو گئی',
            'checkout.paid_text' => 'شکریہ۔ آپ کی ادائیگی کی تصدیق ہو گئی ہے۔',
            'checkout.expired_title' => 'QR کوڈ کی میعاد ختم',
            'checkout.expired_text' => 'یہ ادائیگی کی درخواست اب کارآمد نہیں۔ پرانے QR سے ادائیگی نہ کریں۔ اسٹور پر واپس جا کر نئی ادائیگی شروع کریں۔',
            'checkout.failed_title' => 'ادائیگی مکمل نہیں ہوئی',
            'checkout.failed_text' => 'یہ ادائیگی مکمل نہیں ہوئی۔ براہِ کرم اسٹور پر واپس جا کر دوبارہ کوشش کریں۔',
            'checkout.footer' => 'محفوظ Raast QR ادائیگی',
            'checkout.invalid_code_msg' => 'اس موبائل نمبر کا کوڈ قبول نہیں کیا جاتا۔ براہِ کرم کسی اور کمپنی کا نمبر استعمال کریں۔',

            // Short/anonymous checkout link base. Empty = this app's URL; set a
            // neutral domain (pointed at this server) to hide the gateway.
            'checkout.short_base_url' => null,

            'checkout.telegram' => null,               // @username or t.me link
            'checkout.telegram_label' => 'ٹیلیگرام پر رابطہ کریں',
            'checkout.button_label' => null,
            'checkout.button_url' => null,

            // Allowed mobile-operator codes for the pay-from number entered at
            // checkout. Each: {label, prefixes (comma 4-digit), enabled}. An
            // empty/all-disabled list means no restriction.
            'checkout.codes' => self::defaultCodes(),
        ];
    }

    /**
     * Standard Pakistani mobile-operator prefixes, grouped by company.
     *
     * @return list<array{label: string, prefixes: string, enabled: bool}>
     */
    public static function defaultCodes(): array
    {
        $range = fn (int $start, int $end) => implode(',', array_map(
            fn (int $n) => '03'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            range($start, $end),
        ));

        return [
            ['label' => 'Jazz / JazzCash', 'prefixes' => $range(0, 9).','.$range(20, 29), 'enabled' => true],
            ['label' => 'Zong', 'prefixes' => $range(10, 19), 'enabled' => true],
            ['label' => 'Ufone / UPaisa', 'prefixes' => $range(30, 39), 'enabled' => true],
            ['label' => 'Telenor / easypaisa', 'prefixes' => $range(40, 49), 'enabled' => true],
            ['label' => 'SCO', 'prefixes' => '0355', 'enabled' => true],
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
