<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Support\CheckoutTheme;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class CheckoutBrandingTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    private function settings(): Settings
    {
        return app(Settings::class);
    }

    private function pendingPayment(): Payment
    {
        [$merchant, $secret] = $this->makeMerchant();
        $this->makeAccount();
        $this->createPayment($merchant, $secret, ['amount' => 1500])->assertCreated();

        return Payment::query()->sole();
    }

    public function test_checkout_defaults_to_urdu_rtl_with_nastaliq_font(): void
    {
        $payment = $this->pendingPayment();

        $this->get(route('checkout.show', $payment->payment_id))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('lang="ur"', false)
            ->assertSee('Noto+Nastaliq+Urdu', false)
            ->assertSee('Raast QR کے ذریعے محفوظ ادائیگی'); // default tagline
    }

    public function test_admin_can_save_checkout_branding(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->post(route('admin.checkout.update'), [
            'language' => 'ur', 'font' => 'nastaliq',
            'primary_color' => '#112233', 'accent_color' => '#445566',
            'tagline' => 'میرا اسٹور',
            'telegram' => '@mystore', 'telegram_label' => 'رابطہ',
            'button_label' => 'مدد', 'button_url' => 'https://help.example.com',
            'codes_json' => json_encode([['label' => 'Jazz', 'prefixes' => '0300, 0301, bad', 'enabled' => true]]),
        ])->assertRedirect(route('admin.checkout.edit'))->assertSessionHas('success');

        $this->assertSame('#112233', $this->settings()->get('checkout.primary_color'));
        $this->assertSame('میرا اسٹور', $this->settings()->get('checkout.tagline'));
        $this->assertSame('@mystore', $this->settings()->get('checkout.telegram'));

        $codes = $this->settings()->get('checkout.codes');
        $this->assertSame('0300,0301', $codes[0]['prefixes']); // "bad" dropped
        $this->assertTrue($codes[0]['enabled']);
    }

    public function test_telegram_and_custom_button_render_when_set(): void
    {
        $this->settings()->set([
            'checkout.telegram' => '@mystore',
            'checkout.button_label' => 'Help',
            'checkout.button_url' => 'https://help.example.com',
        ]);
        $payment = $this->pendingPayment();

        $this->get(route('checkout.show', $payment->payment_id))
            ->assertSee('https://t.me/mystore', false)
            ->assertSee('https://help.example.com', false)
            ->assertSee('Help');
    }

    public function test_logo_renders_when_set(): void
    {
        $this->settings()->set(['checkout.logo_path' => 'mylogo.png']);
        $payment = $this->pendingPayment();

        $this->get(route('checkout.show', $payment->payment_id))
            ->assertSee('branding/mylogo.png', false);
    }

    public function test_a_blocked_mobile_code_is_rejected_at_checkout(): void
    {
        $this->settings()->set([
            'checkout.codes' => [['label' => 'Jazz', 'prefixes' => '0300', 'enabled' => true]],
            'checkout.invalid_code_msg' => 'This code is blocked.',
        ]);
        $payment = $this->pendingPayment();

        // 0345 (Telenor) is not in the allowed list.
        $this->post(route('checkout.phone', $payment->payment_id), ['claimed_phone' => '03451234567'])
            ->assertSessionHasErrors(['claimed_phone' => 'This code is blocked.']);
        $this->assertNull($payment->fresh()->claimed_phone);

        // 0300 (Jazz) is allowed.
        $this->post(route('checkout.phone', $payment->payment_id), ['claimed_phone' => '03001234567'])
            ->assertSessionHasNoErrors();
        $this->assertSame('03001234567', $payment->fresh()->claimed_phone);
    }

    public function test_an_empty_code_list_allows_any_number(): void
    {
        $this->settings()->set(['checkout.codes' => []]);
        $payment = $this->pendingPayment();

        $this->post(route('checkout.phone', $payment->payment_id), ['claimed_phone' => '03451234567'])
            ->assertSessionHasNoErrors();
        $this->assertSame('03451234567', $payment->fresh()->claimed_phone);
    }

    public function test_theme_helpers(): void
    {
        $this->settings()->set([
            'checkout.telegram' => 'mystore',
            'checkout.button_label' => 'X',
            'checkout.button_url' => 'not-a-url',
            'checkout.codes' => [
                ['label' => 'A', 'prefixes' => '0300,0301', 'enabled' => true],
                ['label' => 'B', 'prefixes' => '0345', 'enabled' => false],
            ],
        ]);
        $theme = new CheckoutTheme($this->settings());

        $this->assertSame('https://t.me/mystore', $theme->telegramUrl());
        $this->assertNull($theme->button());               // invalid URL -> no button
        $this->assertEqualsCanonicalizing(['0300', '0301'], $theme->allowedPrefixes()); // B disabled
        $this->assertTrue($theme->allows('03001234567'));
        $this->assertFalse($theme->allows('03451234567'));
    }
}
