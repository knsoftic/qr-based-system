<?php

namespace Tests\Unit;

use App\Payments\Emv\Crc16;
use App\Payments\Emv\Tlv;
use App\Payments\Exceptions\UnsupportedPayloadException;
use App\Payments\Providers\ProviderRegistry;
use Database\Seeders\DemoSeeder;
use Tests\TestCase;

class EmvcoProviderTest extends TestCase
{
    public function test_crc_matches_the_ccitt_false_check_value(): void
    {
        $this->assertSame('29B1', Crc16::checksum('123456789'));
    }

    public function test_tlv_round_trips(): void
    {
        $payload = DemoSeeder::samplePayload();

        $this->assertSame($payload, Tlv::encode(Tlv::parse($payload)));
    }

    public function test_it_inspects_a_valid_payload_and_masks_identifiers(): void
    {
        $parsed = $this->provider('raast')->inspect(DemoSeeder::samplePayload());

        $this->assertSame('emvco-mpm', $parsed->format);
        $this->assertSame('static', $parsed->summary['initiation']);
        $this->assertSame('DEMO STORE', $parsed->summary['merchant_name']);
        $this->assertSame('com.example.pay', $parsed->summary['accounts'][0]['gui']);
        $this->assertStringNotContainsString('1234567890', json_encode($parsed->summary));
        $this->assertNull(Tlv::find($parsed->template['items'], '63'));
    }

    public function test_it_builds_a_dynamic_payload_changing_only_allowed_fields(): void
    {
        $provider = $this->provider('raast');
        $original = Tlv::parse(DemoSeeder::samplePayload());
        $template = $provider->inspect(DemoSeeder::samplePayload())->template;

        $dynamic = $provider->build($template, '1500.00', 'TXN261028153045123456');
        $items = Tlv::parse($dynamic);

        $this->assertTrue(Crc16::isValid($dynamic));
        $this->assertSame('12', Tlv::find($items, '01'));
        $this->assertSame('1500.00', Tlv::find($items, '54'));
        $this->assertSame('TXN261028153045123456', Tlv::find(Tlv::parse(Tlv::find($items, '62')), '05'));
        $this->assertSame('SHOP01', Tlv::find(Tlv::parse(Tlv::find($items, '62')), '07'));

        foreach (['00', '26', '52', '53', '58', '59', '60'] as $id) {
            $this->assertSame(Tlv::find($original, $id), Tlv::find($items, $id), "Tag {$id} must not change");
        }

        // The dynamic payload is itself a valid, verifiable EMVCo payload.
        $this->assertSame('dynamic', $provider->inspect($dynamic)->summary['initiation']);
    }

    public function test_tip_fields_are_removed_so_the_amount_is_exact(): void
    {
        $withTip = Crc16::seal(Tlv::encode(Tlv::put(Tlv::without(Tlv::parse(DemoSeeder::samplePayload()), ['63']), '55', '01')));
        $provider = $this->provider('emvco');

        $dynamic = $provider->build($provider->inspect($withTip)->template, '10.00', 'TXN1');

        $this->assertNull(Tlv::find(Tlv::parse($dynamic), '55'));
    }

    public function test_it_rejects_a_tampered_checksum(): void
    {
        $payload = DemoSeeder::samplePayload();
        $tampered = str_replace('DEMO STORE', 'EVIL STORE', $payload);

        $this->expectException(UnsupportedPayloadException::class);
        $this->expectExceptionMessage('Checksum');
        $this->provider('emvco')->inspect($tampered);
    }

    public function test_it_rejects_non_emv_content(): void
    {
        $this->expectException(UnsupportedPayloadException::class);
        $this->provider('emvco')->inspect('https://example.com/pay?to=someone');
    }

    public function test_it_rejects_a_payload_missing_mandatory_fields(): void
    {
        $items = Tlv::without(Tlv::without(Tlv::parse(DemoSeeder::samplePayload()), ['63']), ['59']);

        $this->expectException(UnsupportedPayloadException::class);
        $this->expectExceptionMessage('59');
        $this->provider('emvco')->inspect(Crc16::seal(Tlv::encode($items)));
    }

    public function test_provider_rules_are_enforced(): void
    {
        $items = Tlv::put(Tlv::without(Tlv::parse(DemoSeeder::samplePayload()), ['63']), '58', 'IN');
        $payload = Crc16::seal(Tlv::encode($items));

        // The generic adapter accepts it ...
        $this->assertSame('IN', $this->provider('emvco')->inspect($payload)->summary['country']);

        // ... the Raast profile does not.
        $this->expectException(UnsupportedPayloadException::class);
        $this->provider('raast')->inspect($payload);
    }

    public function test_it_refuses_invalid_amounts_when_building(): void
    {
        $provider = $this->provider('emvco');
        $template = $provider->inspect(DemoSeeder::samplePayload())->template;

        $this->expectException(UnsupportedPayloadException::class);
        $provider->build($template, '0', 'TXN1');
    }

    private function provider(string $key)
    {
        return app(ProviderRegistry::class)->make($key);
    }
}
