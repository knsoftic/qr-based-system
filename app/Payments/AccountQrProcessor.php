<?php

namespace App\Payments;

use App\Enums\DecodeStatus;
use App\Models\PaymentAccount;
use App\Payments\Exceptions\QrDecodeException;
use App\Payments\Exceptions\UnsupportedPayloadException;
use App\Payments\Providers\ProviderRegistry;
use App\Payments\Qr\QrDecoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * QR Upload -> QR Decode -> Payload Extract -> Payload Template.
 */
class AccountQrProcessor
{
    public function __construct(
        private readonly QrDecoder $decoder,
        private readonly ProviderRegistry $providers,
    ) {
    }

    public function process(PaymentAccount $account, UploadedFile $image): PaymentAccount
    {
        $oldPath = $account->qr_image_path;
        $account->qr_image_path = $image->store('qr-codes', 'local');

        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        $account->forceFill([
            'raw_payload' => null,
            'payload_template' => null,
            'payload_hash' => null,
            'payload_summary' => null,
            'decoded_at' => null,
        ]);

        try {
            $payload = trim($this->decoder->decodeFile(Storage::disk('local')->path($account->qr_image_path)));
            $hash = hash('sha256', $payload);

            $duplicate = PaymentAccount::query()
                ->where('payload_hash', $hash)
                ->when($account->exists, fn ($query) => $query->whereKeyNot($account->getKey()))
                ->first();

            if ($duplicate) {
                throw new UnsupportedPayloadException("This QR is already registered on account \"{$duplicate->name}\".");
            }

            $parsed = $this->providers->make($account->provider)->inspect($payload);

            $account->forceFill([
                'decode_status' => DecodeStatus::Decoded,
                'decode_error' => null,
                'raw_payload' => $payload,
                'payload_template' => $parsed->template,
                'payload_hash' => $hash,
                'payload_summary' => $parsed->summary,
                'decoded_at' => now(),
            ]);
        } catch (QrDecodeException $e) {
            $this->markFailed($account, DecodeStatus::Failed, $e->getMessage());
        } catch (UnsupportedPayloadException $e) {
            $this->markFailed($account, DecodeStatus::Unsupported, $e->getMessage());
        }

        $account->save();

        return $account;
    }

    private function markFailed(PaymentAccount $account, DecodeStatus $status, string $error): void
    {
        $account->forceFill([
            'decode_status' => $status,
            'decode_error' => $error,
            'is_active' => false,
        ]);
    }
}
