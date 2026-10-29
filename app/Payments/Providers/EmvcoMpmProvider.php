<?php

namespace App\Payments\Providers;

use App\Payments\Emv\Crc16;
use App\Payments\Emv\Tlv;
use App\Payments\Exceptions\UnsupportedPayloadException;
use DateTimeInterface;

/**
 * EMVCo QR Code Specification for Payment Systems, Merchant Presented Mode.
 *
 * A payload is only accepted after the payload format indicator, the TLV
 * structure, the mandatory data objects, the provider rules and the CRC have
 * all been verified. When building a dynamic QR only these fields change:
 *
 *   01        Point of Initiation Method -> "12" (dynamic, single use)
 *   54        Transaction Amount
 *   55/56/57  Tip / convenience fee      -> removed so the amount is exact
 *   62.05     Reference Label            -> gateway transaction ID
 *   63        CRC                        -> recalculated
 *
 * Merchant account information (02-51), merchant name, city, MCC, currency
 * and country are copied unchanged from the authorized QR.
 */
class EmvcoMpmProvider implements QrProvider
{
    use DescribesFields;

    /**
     * tag => [name, what it does, what the gateway does with it]
     * behaviour: fixed (copied unchanged) | editable | per_payment | checksum | removed
     */
    private const FIELDS = [
        '00' => ['Payload format indicator', 'Version of the EMVCo QR format. Always "01".', 'fixed'],
        '01' => ['Point of initiation', '"11" = static QR (reusable), "12" = dynamic QR (one payment, amount fixed).', 'per_payment'],
        '52' => ['Merchant category code (MCC)', 'ISO 18245 code for the type of business, e.g. 5999 = retail.', 'fixed'],
        '53' => ['Transaction currency', 'ISO 4217 numeric currency, e.g. 586 = PKR.', 'fixed'],
        '54' => ['Transaction amount', 'Amount the payer must pay.', 'per_payment'],
        '55' => ['Tip or convenience indicator', 'Asks the payer for a tip / fee. Removed so the amount stays exact.', 'removed'],
        '56' => ['Convenience fee (fixed)', 'Fixed fee added by the merchant. Removed so the amount stays exact.', 'removed'],
        '57' => ['Convenience fee (percent)', 'Percentage fee added by the merchant. Removed so the amount stays exact.', 'removed'],
        '58' => ['Country code', 'ISO 3166 country of the merchant, e.g. PK.', 'fixed'],
        '59' => ['Merchant name', 'Name shown to the payer in the banking app (max 25 characters).', 'editable'],
        '60' => ['Merchant city', 'City shown to the payer (max 15 characters).', 'editable'],
        '61' => ['Postal code', 'Merchant postal code.', 'fixed'],
        '62' => ['Additional data', 'Template with references and labels (sub-fields below).', 'per_payment'],
        '63' => ['CRC checksum', 'Check value over everything before it. Detects a damaged or edited QR.', 'checksum'],
        '64' => ['Merchant info (other language)', 'Template with the merchant name / city in another language.', 'fixed'],
    ];

    private const ADDITIONAL_DATA = [
        '01' => ['Bill number', 'Invoice or bill number.', 'fixed'],
        '02' => ['Mobile number', 'Mobile number for top-ups / bills.', 'fixed'],
        '03' => ['Store label', 'Store or branch identifier.', 'fixed'],
        '04' => ['Loyalty number', 'Loyalty card number.', 'fixed'],
        '05' => ['Reference label', 'Reference of the payment. The gateway writes each payment\'s transaction ID here.', 'per_payment'],
        '06' => ['Customer label', 'Customer identifier.', 'fixed'],
        '07' => ['Terminal label', 'Terminal / till identifier.', 'fixed'],
        '08' => ['Purpose of transaction', 'Why the payment is made.', 'fixed'],
        '09' => ['Additional consumer data request', 'Data the app should ask the payer for.', 'fixed'],
    ];

    private const LANGUAGE_TEMPLATE = [
        '00' => ['Language preference', 'ISO 639 language code.', 'fixed'],
        '01' => ['Merchant name (alternate language)', 'Merchant name in that language.', 'fixed'],
        '02' => ['Merchant city (alternate language)', 'Merchant city in that language.', 'fixed'],
    ];

    private const MANDATORY = [
        '52' => 'Merchant Category Code',
        '53' => 'Transaction Currency',
        '58' => 'Country Code',
        '59' => 'Merchant Name',
        '60' => 'Merchant City',
    ];

    private const MAX_REFERENCE_LENGTH = 25;

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

        if (! str_starts_with($payload, '000201')) {
            throw new UnsupportedPayloadException('Not an EMVCo merchant QR: the payload format indicator (00 = "01") is missing.');
        }

        if (! Crc16::isValid($payload)) {
            throw new UnsupportedPayloadException('Checksum (tag 63) is missing or does not match. The QR may be damaged or edited.');
        }

        $items = Tlv::parse($payload);
        $ids = array_column($items, 'id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new UnsupportedPayloadException('Duplicate data objects found at the root level.');
        }

        if (end($ids) !== '63') {
            throw new UnsupportedPayloadException('The CRC (tag 63) must be the last data object.');
        }

        $initiation = Tlv::find($items, '01');
        if ($initiation !== null && ! in_array($initiation, ['11', '12'], true)) {
            throw new UnsupportedPayloadException("Unknown point of initiation method \"{$initiation}\".");
        }

        $accounts = $this->merchantAccounts($items);
        if ($accounts === []) {
            throw new UnsupportedPayloadException('No merchant account information (tags 02-51) found.');
        }

        foreach (self::MANDATORY as $id => $label) {
            if (Tlv::find($items, $id) === null) {
                throw new UnsupportedPayloadException("Mandatory field {$id} ({$label}) is missing.");
            }
        }

        $this->assertFormat(Tlv::find($items, '52'), '/^\d{4}$/', 'Merchant Category Code');
        $this->assertFormat(Tlv::find($items, '53'), '/^\d{3}$/', 'Transaction Currency');
        $this->assertFormat(Tlv::find($items, '58'), '/^[A-Z]{2}$/', 'Country Code');

        $existingAmount = Tlv::find($items, '54');
        if ($existingAmount !== null && ! $this->isValidAmount($existingAmount)) {
            throw new UnsupportedPayloadException('The QR contains an invalid transaction amount (tag 54).');
        }

        // We write the reference label into tag 62, so it must be well formed.
        $additional = Tlv::find($items, '62');
        if ($additional !== null) {
            Tlv::parse($additional);
        }

        $this->applyRules($items);

        return new ParsedPayload(
            format: 'emvco-mpm',
            template: ['items' => Tlv::without($items, ['63'])],
            summary: $this->summarise($items, $accounts),
        );
    }

    // EMVCo MPM has no expiry data object, so $expiresAt is not written.
    public function build(array $template, string $amount, string $reference, ?DateTimeInterface $expiresAt = null): string
    {
        if (! $this->isValidAmount($amount)) {
            throw new UnsupportedPayloadException("Invalid amount \"{$amount}\".");
        }

        if (! preg_match('/^[A-Za-z0-9\-]{1,'.self::MAX_REFERENCE_LENGTH.'}$/', $reference)) {
            throw new UnsupportedPayloadException('Invalid reference label.');
        }

        $items = $template['items'] ?? [];
        if ($items === [] || $items[0]['id'] !== '00' || $items[0]['value'] !== '01') {
            throw new UnsupportedPayloadException('The stored payload template is invalid.');
        }

        $items = Tlv::without($items, ['55', '56', '57', '63']);
        $items = Tlv::put($items, '01', '12');
        $items = Tlv::put($items, '54', $amount);
        $items = Tlv::put($items, '62', $this->additionalDataWithReference(Tlv::find($items, '62'), $reference));

        return Crc16::seal(Tlv::encode($items));
    }

    public function describe(string $payload): array
    {
        $lookup = function (string $id) {
            $tag = (int) $id;

            return self::FIELDS[$id] ?? match (true) {
                $tag >= 2 && $tag <= 25 => ['Card scheme account', 'Merchant identifier for a card scheme (Visa, Mastercard, UnionPay...).', 'fixed'],
                $tag >= 26 && $tag <= 51 => ['Merchant account information', 'Template identifying the receiving account (sub-fields below).', 'fixed'],
                $tag >= 80 => ['Unreserved template', 'Scheme or bank specific data.', 'fixed'],
                default => ['Reserved field '.$id, 'Reserved for future EMVCo use.', 'fixed'],
            };
        };

        $children = function (string $id) {
            $tag = (int) $id;

            return match (true) {
                $id === '62' => self::ADDITIONAL_DATA,
                $id === '64' => self::LANGUAGE_TEMPLATE,
                ($tag >= 26 && $tag <= 51) || $tag >= 80 => [
                    '00' => ['Globally unique identifier', 'Identifies the payment scheme / network of this account.', 'fixed'],
                    '01' => ['Account / merchant identifier', 'Account number, IBAN or merchant ID within that scheme (set by the acquirer, not editable).', 'fixed'],
                ],
                default => [],
            };
        };

        return $this->missingEditableRows($this->rows(Tlv::parse($payload), $lookup, $children), $lookup);
    }

    public function editableFields(): array
    {
        return [
            '59' => ['label' => 'Merchant name', 'max' => 25, 'required' => true, 'help' => 'Shown to the payer in the banking app.'],
            '60' => ['label' => 'Merchant city', 'max' => 15, 'required' => true, 'help' => 'Shown to the payer.'],
        ];
    }

    public function applyEdits(array $template, array $values): string
    {
        $items = $this->editItems($template['items'] ?? [], $values, fn (string $tag, string $value) => $value);

        return Crc16::seal(Tlv::encode(Tlv::without($items, ['63'])));
    }

    private function additionalDataWithReference(?string $additional, string $reference): string
    {
        $children = $additional === null ? [] : Tlv::parse($additional);
        $encoded = Tlv::encode(Tlv::put($children, '05', $reference));

        if (mb_strlen($encoded, 'UTF-8') > 99) {
            throw new UnsupportedPayloadException('Additional data (tag 62) would exceed 99 characters.');
        }

        return $encoded;
    }

    private function applyRules(array $items): void
    {
        $country = $this->rules['country_code'] ?? null;
        if ($country !== null && Tlv::find($items, '58') !== $country) {
            throw new UnsupportedPayloadException("This provider only accepts QR codes for country {$country}.");
        }

        $currency = $this->rules['currency_numeric'] ?? null;
        if ($currency !== null && Tlv::find($items, '53') !== $currency) {
            throw new UnsupportedPayloadException("This provider only accepts QR codes in currency {$currency}.");
        }
    }

    /**
     * Merchant account information: 02-25 are primitive (card schemes),
     * 26-51 are templates with a globally unique identifier in sub-tag 00.
     *
     * @return list<array{tag: string, gui: ?string, identifiers: array<string, string>}>
     */
    private function merchantAccounts(array $items): array
    {
        $accounts = [];

        foreach ($items as $item) {
            $id = (int) $item['id'];

            if ($id >= 2 && $id <= 25) {
                $accounts[] = ['tag' => $item['id'], 'gui' => null, 'identifiers' => ['value' => $item['value']]];
            } elseif ($id >= 26 && $id <= 51) {
                $children = Tlv::parse($item['value']);
                $identifiers = [];

                foreach ($children as $child) {
                    if ($child['id'] !== '00') {
                        $identifiers[$child['id']] = $child['value'];
                    }
                }

                $accounts[] = ['tag' => $item['id'], 'gui' => Tlv::find($children, '00'), 'identifiers' => $identifiers];
            }
        }

        return $accounts;
    }

    private function summarise(array $items, array $accounts): array
    {
        return [
            'format' => 'EMVCo Merchant Presented Mode',
            'label' => Tlv::find($items, '59'),
            'details' => [
                'Format' => 'EMVCo Merchant Presented Mode',
                'Original QR type' => Tlv::find($items, '01') === '12' ? 'Dynamic' : 'Static',
                'Merchant name' => Tlv::find($items, '59'),
                'City / country' => Tlv::find($items, '60').', '.Tlv::find($items, '58'),
                'Currency (ISO 4217)' => Tlv::find($items, '53'),
                'Category code' => Tlv::find($items, '52'),
            ],
            'initiation' => Tlv::find($items, '01') === '12' ? 'dynamic' : 'static',
            'merchant_name' => Tlv::find($items, '59'),
            'merchant_city' => Tlv::find($items, '60'),
            'country' => Tlv::find($items, '58'),
            'currency' => Tlv::find($items, '53'),
            'mcc' => Tlv::find($items, '52'),
            'has_fixed_amount' => Tlv::find($items, '54') !== null,
            'accounts' => array_map(fn (array $account) => [
                'tag' => $account['tag'],
                'gui' => $account['gui'],
                'identifiers' => array_map($this->mask(...), $account['identifiers']),
            ], $accounts),
            'tags' => array_column($items, 'id'),
            'dynamic_fields' => [
                '01' => 'Point of initiation (set to dynamic)',
                '54' => 'Transaction amount',
                '62.05' => 'Reference label (transaction ID)',
                '63' => 'CRC checksum (recalculated)',
            ],
            // EMVCo MPM defines no date/time data object, so none is written.
            'datetime_field' => null,
            'checksum' => 'valid',
        ];
    }

    private function mask(string $value): string
    {
        $length = mb_strlen($value, 'UTF-8');

        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return mb_substr($value, 0, 4, 'UTF-8').str_repeat('•', min($length - 8, 10)).mb_substr($value, -4, null, 'UTF-8');
    }

    private function isValidAmount(string $amount): bool
    {
        return strlen($amount) <= 13
            && preg_match('/^\d+(\.\d{1,2})?$/', $amount) === 1
            && (float) $amount > 0;
    }

    private function assertFormat(?string $value, string $pattern, string $label): void
    {
        if ($value === null || preg_match($pattern, $value) !== 1) {
            throw new UnsupportedPayloadException("{$label} has an invalid format.");
        }
    }
}
