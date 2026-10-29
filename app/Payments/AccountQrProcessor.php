<?php

namespace App\Payments;

use App\Enums\DecodeStatus;
use App\Models\PaymentAccount;
use App\Payments\Exceptions\QrDecodeException;
use App\Payments\Exceptions\UnsupportedPayloadException;
use App\Payments\Providers\ParsedPayload;
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

            [$provider, $parsed] = $this->inspect($account->provider, $payload);

            $account->forceFill([
                'provider' => $provider,
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

    /**
     * Change admin-editable fields (e.g. the IBAN) of a decoded account. The
     * adapter rebuilds the payload with a new checksum, and the result must
     * pass the same verification as an uploaded QR before it is saved.
     *
     * @param  array<string, ?string>  $changes  tag => new value
     *
     * @throws UnsupportedPayloadException
     */
    public function edit(PaymentAccount $account, array $changes): PaymentAccount
    {
        $provider = $this->providers->make($account->provider);
        $payload = $provider->applyEdits($account->payload_template, $changes);
        $hash = hash('sha256', $payload);

        $duplicate = PaymentAccount::query()->where('payload_hash', $hash)->whereKeyNot($account->getKey())->first();
        if ($duplicate) {
            throw new UnsupportedPayloadException("Account \"{$duplicate->name}\" already uses exactly this QR data.");
        }

        $parsed = $provider->inspect($payload);
        $previous = $account->payload_summary['edited_tags'] ?? [];

        $account->forceFill([
            'raw_payload' => $payload,
            'payload_template' => $parsed->template,
            'payload_hash' => $hash,
            'payload_summary' => $parsed->summary + [
                'edited_at' => now()->toIso8601String(),
                'edited_tags' => array_values(array_unique([...$previous, ...array_map('strval', array_keys($changes))])),
            ],
        ])->save();

        return $account;
    }

    /**
     * One named provider, or (auto) every provider in config order until one verifies.
     *
     * @return array{0: string, 1: ParsedPayload}
     */
    private function inspect(string $provider, string $payload): array
    {
        if ($provider !== ProviderRegistry::AUTO) {
            return [$provider, $this->providers->make($provider)->inspect($payload)];
        }

        $reasons = [];

        foreach ($this->providers->options() as $key => $name) {
            try {
                return [$key, $this->providers->make($key)->inspect($payload)];
            } catch (UnsupportedPayloadException $e) {
                $reasons[] = "{$name}: {$e->getMessage()}";
            }
        }

        throw new UnsupportedPayloadException('No supported QR format recognised this payload. '.implode(' | ', $reasons));
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
