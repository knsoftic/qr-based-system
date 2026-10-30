<?php

namespace App\Payments\Providers;

use App\Payments\Emv\Crc16;
use App\Payments\Emv\Tlv;
use App\Payments\Exceptions\UnsupportedPayloadException;
use App\Payments\Raast\Iban;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * SBP Raast P2P QR ("Standard for Interoperable QR Code for Person-to-Person
 * and Merchant Payments", P2P layout) as used by easypaisa, JazzCash and banks:
 *
 *   00 "02" format version     01 "11"/"12" static/dynamic   02 scheme ("00")
 *   03 bank name (optional)    04 IBAN                       05 amount (<= 10)
 *   06 particulars (optional)  07 expiry DDMMYYYYHHMM        10 CRC-16 (incl. "1004")
 *
 * A payload is only accepted after the format version, the TLV structure,
 * the set of tags, the IBAN check digits and the CRC have been verified. When
 * building a dynamic QR only these fields change: 01 -> "12", 05 amount,
 * 07 expiry (the payment's own expiry, local time) and 10 CRC. 06 is written
 * only when the "particulars_reference" rule is enabled.
 */
class RaastP2pProvider implements QrProvider
{
    use DescribesFields;

    private const KNOWN_TAGS = ['00', '01', '02', '03', '04', '05', '06', '07', '10'];

    /**
     * tag => [name, what it does, what the gateway does with it]
     * behaviour: fixed (copied unchanged) | editable | per_payment | checksum
     */
    private const FIELDS = [
        '00' => ['Format version', 'Version of the Raast QR standard. Always "02" for a Raast P2P QR.', 'fixed'],
        '01' => ['Point of initiation', '"11" = static QR (reusable, payer types the amount). "12" = dynamic QR (one payment, amount fixed).', 'per_payment'],
        '02' => ['Scheme identifier', 'Payment scheme. Banking apps (easypaisa, JazzCash, banks) use "00".', 'fixed'],
        '03' => ['Bank / wallet name', 'Optional name of the receiving bank or wallet shown to the payer (max 25 characters).', 'editable'],
        '04' => ['IBAN (receiving account)', 'The account that receives the money. Every payment made with this QR is credited to this IBAN.', 'editable'],
        '05' => ['Amount (PKR)', 'Amount the payer must send. Filled in by the banking app; the payer cannot change it.', 'per_payment'],
        '06' => ['Particulars', 'Optional payment description shown to the payer and on the statement (max 30 characters).', 'editable'],
        '07' => ['Expiry date / time', 'After this moment banking apps refuse the QR. Format DDMMYYYYHHMM, Pakistan time.', 'per_payment'],
        '10' => ['CRC checksum', 'Check value over everything before it. Detects a damaged or edited QR.', 'checksum'],
    ];

    private const SCHEMES = ['00', '30'];

    public function __construct(
        private readonly string $key,
        private readonly array $rules = [],
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function inspect(string $payload): ParsedPayload
    {
        $payload = trim($payload);

        if (! str_starts_with($payload, '000202')) {
            throw new UnsupportedPayloadException('Not a Raast P2P QR: the format version (00 = "02") is missing.');
        }

        if (! Crc16::isValid($payload, '10')) {
            throw new UnsupportedPayloadException('Checksum (tag 10) is missing or does not match. The QR may be damaged or edited.');
        }

        $items = Tlv::parse($payload);
        $ids = array_column($items, 'id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new UnsupportedPayloadException('Duplicate data objects found.');
        }

        if (end($ids) !== '10') {
            throw new UnsupportedPayloadException('The CRC (tag 10) must be the last data object.');
        }

        if ($unknown = array_diff($ids, self::KNOWN_TAGS)) {
            throw new UnsupportedPayloadException('Unknown data object(s) '.implode(', ', $unknown).'; this QR is not modified.');
        }

        $initiation = Tlv::find($items, '01');
        if ($initiation !== null && ! in_array($initiation, ['11', '12'], true)) {
            throw new UnsupportedPayloadException("Unknown point of initiation method \"{$initiation}\".");
        }

        $scheme = Tlv::find($items, '02');
        if (! in_array($scheme, self::SCHEMES, true)) {
            throw new UnsupportedPayloadException('Unknown or missing scheme identifier (tag 02).');
        }

        $iban = (string) Tlv::find($items, '04');
        if (! Iban::isValid($iban)) {
            throw new UnsupportedPayloadException('Tag 04 is not a valid Pakistani IBAN (format or check digits).');
        }

        $amount = Tlv::find($items, '05');
        if ($amount !== null && ! $this->isValidAmount($amount)) {
            throw new UnsupportedPayloadException('The QR contains an invalid amount (tag 05).');
        }

        $expiry = Tlv::find($items, '07');
        if ($expiry !== null && $this->parseExpiry($expiry) === null) {
            throw new UnsupportedPayloadException('The QR contains an invalid expiry (tag 07, expected DDMMYYYYHHMM).');
        }

        if (mb_strlen((string) Tlv::find($items, '03'), 'UTF-8') > 25 || mb_strlen((string) Tlv::find($items, '06'), 'UTF-8') > 30) {
            throw new UnsupportedPayloadException('Bank name (03) or particulars (06) is longer than allowed.');
        }

        return new ParsedPayload(
            format: 'raast-p2p',
            template: ['items' => Tlv::without($items, ['10'])],
            summary: $this->summarise($items, $iban, $amount, $expiry),
        );
    }

    public function build(array $template, string $amount, string $reference, ?DateTimeInterface $expiresAt = null): string
    {
        $items = $template['items'] ?? [];
        if ($items === [] || $items[0]['id'] !== '00' || $items[0]['value'] !== '02' || ! Iban::isValid((string) Tlv::find($items, '04'))) {
            throw new UnsupportedPayloadException('The stored payload template is invalid.');
        }

        $formatted = $this->formatAmount($amount);

        // Minute precision. expires_at is stored at second precision, so round
        // on whole seconds only (ignore microseconds) to stay consistent with
        // the stored value; round up so the QR never dies before the payment.
        $expiry = CarbonImmutable::instance($expiresAt ?? now()->addMinutes(5))
            ->setTimezone($this->rules['timezone'] ?? 'Asia/Karachi')
            ->startOfSecond();
        if ($expiry->second > 0) {
            $expiry = $expiry->addMinute()->startOfMinute();
        }

        $items = Tlv::without($items, ['05', '07', '10']);
        $items = Tlv::put($items, '01', '12');
        $items = Tlv::put($items, '05', $formatted);

        if (! empty($this->rules['particulars_reference'])) {
            $items = Tlv::put($items, '06', mb_substr($reference, 0, 30, 'UTF-8'));
        }

        $items = Tlv::put($items, '07', $expiry->format('dmYHi'));

        return Crc16::seal(Tlv::encode($items), '10');
    }

    /**
     * A standard static Raast P2P payload for an IBAN (the layout banking apps
     * issue): 00 "02", 01 "11", 02 "00", [03 bank name], 04 IBAN, 10 CRC.
     * Used to add an account when no readable QR image is available.
     */
    public function payloadForIban(string $iban, ?string $bankName = null): string
    {
        $iban = strtoupper(preg_replace('/[\s-]+/', '', $iban));

        if (! Iban::isValid($iban)) {
            throw new UnsupportedPayloadException('This is not a valid Pakistani IBAN (format or check digits are wrong).');
        }

        $items = [
            ['id' => '00', 'value' => '02'],
            ['id' => '01', 'value' => '11'],
            ['id' => '02', 'value' => '00'],
        ];

        if ($bankName = trim((string) $bankName)) {
            $items[] = ['id' => '03', 'value' => mb_substr($bankName, 0, 25, 'UTF-8')];
        }

        $items[] = ['id' => '04', 'value' => $iban];

        return Crc16::seal(Tlv::encode($items), '10');
    }

    public function describe(string $payload): array
    {
        $lookup = function (string $id) {
            if ($id === '06' && $this->writesReference()) {
                return ['Particulars', 'Payment description. The gateway writes each payment\'s transaction ID here.', 'per_payment'];
            }

            return self::FIELDS[$id] ?? ['Unknown field '.$id, 'Not part of the Raast P2P standard.', 'fixed'];
        };

        return $this->missingEditableRows($this->rows(Tlv::parse($payload), $lookup, fn () => []), $lookup);
    }

    public function editableFields(): array
    {
        $fields = [
            '03' => ['label' => 'Bank / wallet name', 'max' => 25, 'required' => false, 'help' => 'Optional. Leave empty to remove.'],
            '04' => ['label' => 'IBAN', 'max' => 24, 'required' => true, 'help' => '24 characters, e.g. PK36SCBL0000001123456702. Check digits are verified.'],
            '06' => ['label' => 'Particulars', 'max' => 30, 'required' => false, 'help' => 'Optional. Leave empty to remove.'],
        ];

        if ($this->writesReference()) {
            unset($fields['06']);
        }

        return $fields;
    }

    private function writesReference(): bool
    {
        return ! empty($this->rules['particulars_reference']);
    }

    public function applyEdits(array $template, array $values): string
    {
        $items = $this->editItems($template['items'] ?? [], $values, function (string $tag, string $value) {
            if ($tag !== '04') {
                return $value;
            }

            $iban = strtoupper(preg_replace('/[\s-]+/', '', $value));
            if (! Iban::isValid($iban)) {
                throw new UnsupportedPayloadException('This is not a valid Pakistani IBAN (format or check digits are wrong).');
            }

            return $iban;
        });

        return Crc16::seal(Tlv::encode(Tlv::without($items, ['10'])), '10');
    }

    /**
     * Whole rupees are written without decimals (as banking apps do: "100"),
     * anything else with two decimals ("100.50").
     */
    private function formatAmount(string $amount): string
    {
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
            throw new UnsupportedPayloadException("Invalid amount \"{$amount}\".");
        }

        $cents = (int) round(((float) $amount) * 100);
        $formatted = $cents % 100 === 0 ? (string) intdiv($cents, 100) : number_format($cents / 100, 2, '.', '');

        if (strlen($formatted) > 10) {
            throw new UnsupportedPayloadException('Amount is too large for a Raast P2P QR (max 10 characters).');
        }

        return $formatted;
    }

    private function isValidAmount(string $amount): bool
    {
        return strlen($amount) <= 10
            && preg_match('/^\d+(\.\d{1,2})?$/', $amount) === 1
            && (float) $amount > 0;
    }

    private function parseExpiry(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{12}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('dmYHi', $value, $this->rules['timezone'] ?? 'Asia/Karachi');

        return $date && $date->format('dmYHi') === $value ? $date : null;
    }

    private function summarise(array $items, string $iban, ?string $amount, ?string $expiry): array
    {
        $expiresAt = $expiry !== null ? $this->parseExpiry($expiry) : null;

        return [
            'format' => 'Raast P2P QR (SBP, IBAN based)',
            'label' => 'IBAN '.Iban::mask($iban).' ('.Iban::bankCode($iban).')',
            'initiation' => Tlv::find($items, '01') === '12' ? 'dynamic' : 'static',
            'details' => array_filter([
                'Format' => 'Raast P2P (version 02, scheme '.Tlv::find($items, '02').')',
                'Original QR type' => Tlv::find($items, '01') === '12' ? 'Dynamic' : 'Static',
                'Bank code' => Iban::bankCode($iban),
                'Bank name (tag 03)' => Tlv::find($items, '03'),
                'Original amount' => $amount !== null ? 'PKR '.$amount : null,
                'Original expiry' => $expiresAt?->format('d/m/Y H:i'),
            ]),
            'accounts' => [
                ['tag' => '04', 'gui' => null, 'identifiers' => ['IBAN' => Iban::mask($iban)]],
            ],
            'tags' => array_column($items, 'id'),
            'dynamic_fields' => array_filter([
                '01' => 'Point of initiation (set to dynamic)',
                '05' => 'Amount',
                '06' => ! empty($this->rules['particulars_reference']) ? 'Particulars (transaction ID)' : null,
                '07' => 'Expiry (the payment\'s own expiry, '.($this->rules['timezone'] ?? 'Asia/Karachi').' time)',
                '10' => 'CRC checksum (recalculated)',
            ]),
            'datetime_field' => '07: expiry, set to each payment\'s QR expiry',
            'checksum' => 'valid',
        ];
    }
}
