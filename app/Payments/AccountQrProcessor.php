<?php

namespace App\Payments;

use App\Enums\DecodeStatus;
use App\Models\PaymentAccount;
use App\Payments\Exceptions\QrDecodeException;
use App\Payments\Exceptions\UnsupportedPayloadException;
use App\Payments\Providers\ParsedPayload;
use App\Payments\Providers\ProviderRegistry;
use App\Payments\Qr\QrDecoder;
use App\Payments\Qr\QrRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * QR Upload -> QR Decode -> Payload Extract -> Payload Template.
 *
 * Three ways in, one verification: an uploaded image, the QR text pasted by
 * the admin (when the image cannot be decoded, e.g. a large logo), or a
 * standard Raast P2P QR built from an IBAN. Every payload must pass the same
 * provider checks (format, checksum, IBAN check digits) before it is saved.
 */
class AccountQrProcessor
{
    private const RAAST_P2P = 'raast_p2p';

    public function __construct(
        private readonly QrDecoder $decoder,
        private readonly ProviderRegistry $providers,
        private readonly QrRenderer $renderer,
    ) {
    }

    public function process(PaymentAccount $account, UploadedFile $image): PaymentAccount
    {
        $this->replaceImage($account, $image->store('qr-codes', 'local'));
        $this->resetPayload($account);

        try {
            $payload = trim($this->decoder->decodeFile(Storage::disk('local')->path($account->qr_image_path)));
            $this->applyPayload($account, $payload, 'image');
        } catch (QrDecodeException $e) {
            $this->markFailed($account, DecodeStatus::Failed, $e->getMessage());
        } catch (UnsupportedPayloadException $e) {
            $this->markFailed($account, DecodeStatus::Unsupported, $e->getMessage());
        }

        $account->save();

        return $account;
    }

    /**
     * QR text pasted by the admin (e.g. read with a phone camera).
     */
    public function processText(PaymentAccount $account, string $payload): PaymentAccount
    {
        $this->resetPayload($account);

        try {
            $this->applyPayload($account, trim($payload), 'text');
            $this->storeRenderedImage($account);
        } catch (UnsupportedPayloadException $e) {
            $this->markFailed($account, DecodeStatus::Unsupported, $e->getMessage());
        }

        $account->save();

        return $account;
    }

    /**
     * Standard Raast P2P QR built from an IBAN: works for JazzCash, easypaisa
     * and bank accounts without any QR image.
     */
    public function processIban(PaymentAccount $account, string $iban, ?string $bankName = null): PaymentAccount
    {
        $account->provider = self::RAAST_P2P;
        $this->resetPayload($account);

        try {
            $payload = $this->providers->make(self::RAAST_P2P)->payloadForIban($iban, $bankName);
            $this->applyPayload($account, $payload, 'iban');
            $this->storeRenderedImage($account);
        } catch (UnsupportedPayloadException $e) {
            $this->markFailed($account, DecodeStatus::Unsupported, $e->getMessage());
        }

        $account->save();

        return $account;
    }

    /**
     * @throws UnsupportedPayloadException
     */
    private function applyPayload(PaymentAccount $account, string $payload, string $source): void
    {
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
            'payload_summary' => $parsed->summary + ['source' => $source],
            'decoded_at' => now(),
        ]);
    }

    private function resetPayload(PaymentAccount $account): void
    {
        $account->forceFill([
            'raw_payload' => null,
            'payload_template' => null,
            'payload_hash' => null,
            'payload_summary' => null,
            'decoded_at' => null,
        ]);
    }

    private function replaceImage(PaymentAccount $account, string $path): void
    {
        $oldPath = $account->qr_image_path;
        $account->qr_image_path = $path;

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }
    }

    /**
     * A clean QR image of the verified payload, so every account has one.
     */
    private function storeRenderedImage(PaymentAccount $account): void
    {
        $path = 'qr-codes/'.Str::uuid().'.png';
        Storage::disk('local')->put($path, $this->renderer->png($account->raw_payload));
        $this->replaceImage($account, $path);
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
