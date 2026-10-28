<?php

namespace Tests\Unit;

use App\Payments\Emv\Crc16;
use App\Payments\Emv\Tlv;
use App\Payments\Exceptions\UnsupportedPayloadException;
use App\Payments\Providers\ProviderRegistry;
use App\Payments\Providers\RaastP2pProvider;
use App\Payments\Raast\Iban;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class RaastP2pProviderTest extends TestCase
{
    use GatewayHelpers;

    public function test_iban_check_digits(): void
    {
        $iban = Iban::make('TMFB0000001234567890');

        $this->assertTrue(Iban::isValid($iban));
        $this->assertFalse(Iban::isValid(substr($iban, 0, -1).'1'));
        $this->assertFalse(Iban::isValid('GB82WEST12345698765432'));
        $this->assertSame('TMFB', Iban::bankCode($iban));
        $this->assertStringNotContainsString('1234567890', Iban::mask($iban));
    }

    public function test_it_inspects_an_easypaisa_style_dynamic_qr(): void
    {
        $parsed = $this->provider()->inspect($this->p2pPayload());

        $this->assertSame('raast-p2p', $parsed->format);
        $this->assertSame('dynamic', $parsed->summary['initiation']);
        $this->assertSame('TMFB', $parsed->summary['details']['Bank code']);
        $this->assertSame('PKR 100', $parsed->summary['details']['Original amount']);
        $this->assertSame('22/11/2026 20:43', $parsed->summary['details']['Original expiry']);
        $this->assertStringNotContainsString('1234567890', json_encode($parsed->summary));
        $this->assertNull(Tlv::find($parsed->template['items'], '10'));
    }

    public function test_it_accepts_a_static_qr_without_amount_or_expiry(): void
    {
        $parsed = $this->provider()->inspect($this->p2pPayload(amount: null, expiry: null));

        $this->assertSame('static', $parsed->summary['initiation']);
    }

    public function test_it_builds_a_dynamic_qr_changing_only_amount_expiry_and_crc(): void
    {
        $original = $this->p2pPayload(extra: ['03' => 'Demo Bank']);
        $template = $this->provider()->inspect($original)->template;

        $dynamic = $this->provider()->build($template, '1500.00', 'TXN261028153045123456', Carbon::parse('2026-10-28 15:35:45', 'UTC'));
        $items = Tlv::parse($dynamic);

        $this->assertTrue(Crc16::isValid($dynamic, '10'));
        $this->assertSame(['00', '01', '02', '03', '04', '05', '07', '10'], array_column($items, 'id'));
        $this->assertSame('12', Tlv::find($items, '01'));
        $this->assertSame('1500', Tlv::find($items, '05'));
        // 15:35:45 UTC = 20:35:45 PKT, rounded up to the next minute.
        $this->assertSame('281020262036', Tlv::find($items, '07'));
        $this->assertNull(Tlv::find($items, '06'));

        foreach (['00', '02', '03', '04'] as $id) {
            $this->assertSame(Tlv::find(Tlv::parse($original), $id), Tlv::find($items, $id), "Tag {$id} must not change");
        }

        $this->assertSame('dynamic', $this->provider()->inspect($dynamic)->summary['initiation']);
    }

    public function test_amounts_are_formatted_like_banking_apps(): void
    {
        $template = $this->provider()->inspect($this->p2pPayload())->template;
        $amount = fn (string $value) => Tlv::find(Tlv::parse($this->provider()->build($template, $value, 'T', now())), '05');

        $this->assertSame('100', $amount('100.00'));
        $this->assertSame('99.50', $amount('99.5'));
        $this->assertSame('0.75', $amount('0.75'));
    }

    public function test_particulars_reference_is_written_only_when_enabled(): void
    {
        $provider = new RaastP2pProvider('raast_p2p', ['timezone' => 'Asia/Karachi', 'particulars_reference' => true]);
        $template = $provider->inspect($this->p2pPayload())->template;

        $dynamic = $provider->build($template, '10', 'TXN261028153045123456', now());

        $this->assertSame('TXN261028153045123456', Tlv::find(Tlv::parse($dynamic), '06'));
    }

    public function test_it_rejects_a_tampered_checksum(): void
    {
        $payload = $this->p2pPayload();
        $tampered = substr($payload, 0, -1).(substr($payload, -1) === '0' ? '1' : '0');

        $this->expectException(UnsupportedPayloadException::class);
        $this->expectExceptionMessage('Checksum');
        $this->provider()->inspect($tampered);
    }

    public function test_it_rejects_an_invalid_iban(): void
    {
        $items = Tlv::without(Tlv::parse($this->p2pPayload()), ['10']);
        $items = Tlv::put($items, '04', 'PK00TMFB0000001234567890');

        $this->expectException(UnsupportedPayloadException::class);
        $this->expectExceptionMessage('IBAN');
        $this->provider()->inspect(Crc16::seal(Tlv::encode($items), '10'));
    }

    public function test_it_rejects_unknown_tags(): void
    {
        $this->expectException(UnsupportedPayloadException::class);
        $this->expectExceptionMessage('Unknown data object');
        $this->provider()->inspect($this->p2pPayload(extra: ['09' => 'X']));
    }

    public function test_it_rejects_an_invalid_expiry(): void
    {
        $this->expectException(UnsupportedPayloadException::class);
        $this->expectExceptionMessage('expiry');
        $this->provider()->inspect($this->p2pPayload(expiry: '321320262599'));
    }

    public function test_formats_do_not_cross_match(): void
    {
        $registry = app(ProviderRegistry::class);

        try {
            $registry->make('raast_p2p')->inspect(DemoSeeder::samplePayload());
            $this->fail('An EMVCo merchant QR must not pass the P2P adapter.');
        } catch (UnsupportedPayloadException) {
        }

        $this->expectException(UnsupportedPayloadException::class);
        $registry->make('emvco')->inspect($this->p2pPayload());
    }

    private function provider(): RaastP2pProvider
    {
        return app(ProviderRegistry::class)->make('raast_p2p');
    }
}
