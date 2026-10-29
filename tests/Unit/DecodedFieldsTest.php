<?php

namespace Tests\Unit;

use App\Payments\Emv\Crc16;
use App\Payments\Emv\Tlv;
use App\Payments\Exceptions\UnsupportedPayloadException;
use App\Payments\Providers\ProviderRegistry;
use App\Payments\Raast\Iban;
use Database\Seeders\DemoSeeder;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class DecodedFieldsTest extends TestCase
{
    use GatewayHelpers;

    public function test_p2p_fields_are_listed_with_names_descriptions_and_behaviour(): void
    {
        $rows = collect(app(ProviderRegistry::class)->make('raast_p2p')->describe($this->p2pPayload()))->keyBy('tag');

        $this->assertSame(['00', '01', '02', '03', '04', '05', '06', '07', '10'], $rows->pluck('tag')->all());
        $this->assertSame('IBAN (receiving account)', $rows['04']['name']);
        $this->assertSame(Iban::make('TMFB0000001234567890'), $rows['04']['value']);
        $this->assertSame('editable', $rows['04']['behaviour']);
        $this->assertSame('per_payment', $rows['05']['behaviour']);
        $this->assertSame('per_payment', $rows['07']['behaviour']);
        $this->assertSame('checksum', $rows['10']['behaviour']);
        $this->assertStringContainsString('credited', $rows['04']['description']);

        // Optional editable fields that are not in the QR are offered too.
        $this->assertFalse($rows['03']['present']);
        $this->assertNull($rows['06']['value']);
    }

    public function test_emvco_templates_are_listed_with_their_sub_fields(): void
    {
        $rows = collect(app(ProviderRegistry::class)->make('emvco')->describe(DemoSeeder::samplePayload()))->keyBy('tag');

        $this->assertSame('Merchant account information', $rows['26']['name']);
        $this->assertSame(['26.00', '26.01'], array_column($rows['26']['children'], 'tag'));
        $this->assertSame('com.example.pay', $rows['26']['children'][0]['value']);
        $this->assertSame('Terminal label', $rows['62']['children'][0]['name']);
        $this->assertSame('editable', $rows['59']['behaviour']);
        $this->assertSame('checksum', $rows['63']['behaviour']);
    }

    public function test_changing_the_iban_rebuilds_a_verified_payload(): void
    {
        $provider = app(ProviderRegistry::class)->make('raast_p2p');
        $template = $provider->inspect($this->p2pPayload())->template;
        $newIban = Iban::make('MEZN0000009876543210');

        $payload = $provider->applyEdits($template, ['04' => strtolower(substr($newIban, 0, 4)).' '.substr($newIban, 4), '03' => 'Meezan']);

        $this->assertTrue(Crc16::isValid($payload, '10'));
        $this->assertSame($newIban, Tlv::find(Tlv::parse($payload), '04'));
        $this->assertSame('Meezan', Tlv::find(Tlv::parse($payload), '03'));
        $this->assertSame('MEZN', $provider->inspect($payload)->summary['details']['Bank code']);
    }

    public function test_an_invalid_iban_is_refused(): void
    {
        $provider = app(ProviderRegistry::class)->make('raast_p2p');
        $template = $provider->inspect($this->p2pPayload())->template;

        $this->expectException(UnsupportedPayloadException::class);
        $this->expectExceptionMessage('IBAN');
        $provider->applyEdits($template, ['04' => 'PK00MEZN0000009876543210']);
    }

    public function test_only_editable_fields_can_change(): void
    {
        $provider = app(ProviderRegistry::class)->make('raast_p2p');
        $template = $provider->inspect($this->p2pPayload())->template;

        $this->expectException(UnsupportedPayloadException::class);
        $this->expectExceptionMessage('cannot be edited');
        $provider->applyEdits($template, ['05' => '999']);
    }

    public function test_optional_fields_can_be_removed_but_required_ones_cannot(): void
    {
        $provider = app(ProviderRegistry::class)->make('raast_p2p');
        $template = $provider->inspect($this->p2pPayload(extra: ['03' => 'Old bank']))->template;

        $this->assertNull(Tlv::find(Tlv::parse($provider->applyEdits($template, ['03' => ''])), '03'));

        $this->expectException(UnsupportedPayloadException::class);
        $provider->applyEdits($template, ['04' => '']);
    }

    public function test_emvco_merchant_name_can_be_edited(): void
    {
        $provider = app(ProviderRegistry::class)->make('emvco');
        $template = $provider->inspect(DemoSeeder::samplePayload())->template;

        $payload = $provider->applyEdits($template, ['59' => 'NEW NAME']);

        $this->assertTrue(Crc16::isValid($payload));
        $this->assertSame('NEW NAME', $provider->inspect($payload)->summary['details']['Merchant name']);
    }
}
