<?php

namespace Tests\Feature;

use App\Enums\DecodeStatus;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Payments\Emv\Crc16;
use App\Payments\Emv\Tlv;
use App\Payments\Providers\ProviderRegistry;
use App\Payments\Raast\Iban;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GatewayHelpers;
use Tests\TestCase;

class AccountSourcesTest extends TestCase
{
    use GatewayHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs($this->makeAdmin());
    }

    public function test_the_standard_payload_for_an_iban_matches_the_banking_app_layout(): void
    {
        $iban = Iban::make('JCMA0000000001237250');
        $payload = app(ProviderRegistry::class)->make('raast_p2p')->payloadForIban(strtolower(substr($iban, 0, 8)).' '.substr($iban, 8), 'JazzCash');

        $this->assertMatchesRegularExpression('/^0002020102110202000308JazzCash0424'.$iban.'1004[0-9A-F]{4}$/', $payload);
        $this->assertTrue(Crc16::isValid($payload, '10'));
    }

    public function test_an_account_can_be_added_by_iban_and_payments_use_it(): void
    {
        $iban = Iban::make('JCMA0000000001237250');

        $this->post(route('admin.accounts.store'), [
            'name' => 'JazzCash Owais',
            'method' => 'iban',
            'iban' => $iban,
            'bank_name' => 'JazzCash',
            'activate' => 1,
        ])->assertSessionHas('success');

        $account = PaymentAccount::query()->sole();
        $this->assertSame('raast_p2p', $account->provider);
        $this->assertSame(DecodeStatus::Decoded, $account->decode_status);
        $this->assertTrue($account->is_active);
        $this->assertSame('iban', $account->payload_summary['source']);
        $this->assertSame('JCMA', $account->payload_summary['details']['Bank code']);
        Storage::disk('local')->assertExists($account->qr_image_path);

        $this->get(route('admin.accounts.show', $account))
            ->assertSee('IBAN (standard Raast P2P QR built by the gateway)')
            ->assertSee($iban);

        [$merchant, $secret] = $this->makeMerchant();
        $this->createPayment($merchant, $secret, ['amount' => 100])->assertCreated();
        $items = Tlv::parse(Payment::query()->sole()->qr_payload);
        $this->assertSame($iban, Tlv::find($items, '04'));
        $this->assertSame('100', Tlv::find($items, '05'));
        $this->assertNotNull(Tlv::find($items, '07'));
    }

    public function test_an_invalid_iban_is_refused_before_anything_is_saved(): void
    {
        $this->post(route('admin.accounts.store'), [
            'name' => 'Bad',
            'method' => 'iban',
            'iban' => 'PK00JCMA0000000001237250',
        ])->assertSessionHasErrors('iban');

        $this->assertSame(0, PaymentAccount::query()->count());
    }

    public function test_the_same_iban_cannot_be_added_twice(): void
    {
        $iban = Iban::make('JCMA0000000001237250');
        $this->post(route('admin.accounts.store'), ['name' => 'One', 'method' => 'iban', 'iban' => $iban]);
        $this->post(route('admin.accounts.store'), ['name' => 'Two', 'method' => 'iban', 'iban' => $iban]);

        $second = PaymentAccount::query()->where('name', 'Two')->sole();
        $this->assertSame(DecodeStatus::Unsupported, $second->decode_status);
        $this->assertStringContainsString('already registered', $second->decode_error);
    }

    public function test_pasted_qr_text_is_verified_like_an_image(): void
    {
        $this->post(route('admin.accounts.store'), [
            'name' => 'Pasted',
            'method' => 'text',
            'provider' => 'auto',
            'qr_text' => "  ".$this->p2pPayload()."\n",
            'activate' => 1,
        ])->assertSessionHas('success');

        $account = PaymentAccount::query()->sole();
        $this->assertSame('raast_p2p', $account->provider);
        $this->assertSame('text', $account->payload_summary['source']);
        $this->assertSame($this->p2pPayload(), $account->raw_payload);
        Storage::disk('local')->assertExists($account->qr_image_path);
    }

    public function test_pasted_text_that_is_not_a_payment_qr_is_rejected(): void
    {
        $this->post(route('admin.accounts.store'), [
            'name' => 'Junk',
            'method' => 'text',
            'qr_text' => 'https://example.com/pay',
        ])->assertSessionHas('error');

        $account = PaymentAccount::query()->sole();
        $this->assertSame(DecodeStatus::Unsupported, $account->decode_status);
        $this->assertFalse($account->is_active);
    }

    public function test_a_qr_hidden_by_a_big_logo_explains_the_alternatives(): void
    {
        $this->post(route('admin.accounts.store'), [
            'name' => 'Logo QR',
            'method' => 'image',
            'qr_image' => $this->qrWithBigLogo($this->p2pPayload()),
        ]);

        $account = PaymentAccount::query()->sole();
        $this->assertSame(DecodeStatus::Failed, $account->decode_status);
        $this->assertStringContainsString('logo', $account->decode_error);
        $this->assertStringContainsString('Add by IBAN', $account->decode_error);
    }

    public function test_an_existing_account_can_be_replaced_by_iban(): void
    {
        $account = $this->makeP2pAccount();
        $iban = Iban::make('JCMA0000000001237250');

        $this->post(route('admin.accounts.qr', $account), ['method' => 'iban', 'iban' => $iban])->assertSessionHas('success');

        $this->assertSame($iban, Tlv::find($account->fresh()->payload_template['items'], '04'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.qr_uploaded', 'auditable_id' => $account->id]);
    }

    /**
     * A low error-correction QR with a large white "logo" painted over its centre.
     */
    private function qrWithBigLogo(string $payload): UploadedFile
    {
        $png = (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::L,
            'scale' => 8,
        ])))->render($payload);

        $image = imagecreatefromstring($png);
        [$w, $h] = [imagesx($image), imagesy($image)];
        imagefilledrectangle($image, (int) ($w * .3), (int) ($h * .3), (int) ($w * .7), (int) ($h * .7), imagecolorallocate($image, 255, 255, 255));

        $path = tempnam(sys_get_temp_dir(), 'qr').'.png';
        imagepng($image, $path);

        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }
}
