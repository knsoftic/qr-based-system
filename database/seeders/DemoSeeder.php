<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\PaymentAccount;
use App\Payments\AccountQrProcessor;
use App\Payments\Emv\Crc16;
use App\Payments\Emv\Tlv;
use App\Payments\Qr\QrRenderer;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Demo data for local testing: one merchant and one payment account built
 * from a generated sample EMVCo QR with fake identifiers.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(AccountQrProcessor $processor, QrRenderer $renderer): void
    {
        $merchant = Merchant::query()->firstOrNew(['name' => 'Demo Store']);

        if (! $merchant->exists) {
            $merchant->fill([
                'email' => 'store@example.com',
                'website' => 'https://store.example.com',
                'is_active' => true,
            ]);
            $secret = $merchant->issueCredentials();
            $merchant->save();

            $this->command?->info("Demo merchant API key: {$merchant->api_key}");
            $this->command?->warn("Demo merchant API secret (shown once): {$secret}");
        }

        if (PaymentAccount::query()->where('name', 'Demo account')->exists()) {
            return;
        }

        $path = storage_path('app/demo-qr.png');
        file_put_contents($path, $renderer->png(self::samplePayload()));

        $account = new PaymentAccount(['name' => 'Demo account', 'provider' => 'raast', 'priority' => 100]);
        $processor->process($account, new UploadedFile($path, 'demo-qr.png', 'image/png', null, true));
        $account->forceFill(['is_active' => $account->isDecoded()])->save();

        $this->command?->info("Demo account decode status: {$account->decode_status->value}");
    }

    /**
     * Static EMVCo merchant QR with fake data (not a real account).
     */
    public static function samplePayload(string $accountNumber = 'PK00DEMO0000001234567890'): string
    {
        return Crc16::seal(Tlv::encode([
            ['id' => '00', 'value' => '01'],
            ['id' => '01', 'value' => '11'],
            ['id' => '26', 'value' => Tlv::object('00', 'com.example.pay').Tlv::object('01', $accountNumber)],
            ['id' => '52', 'value' => '5999'],
            ['id' => '53', 'value' => '586'],
            ['id' => '58', 'value' => 'PK'],
            ['id' => '59', 'value' => 'DEMO STORE'],
            ['id' => '60', 'value' => 'LAHORE'],
            ['id' => '62', 'value' => Tlv::object('07', 'SHOP01')],
        ]));
    }
}
