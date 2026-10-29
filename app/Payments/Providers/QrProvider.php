<?php

namespace App\Payments\Providers;

use App\Payments\Exceptions\UnsupportedPayloadException;
use DateTimeInterface;

/**
 * Isolated adapter for one QR payload specification. All payload reading and
 * writing goes through an adapter; nothing else in the system touches the
 * payload structure.
 */
interface QrProvider
{
    public function key(): string;

    /**
     * Identify and verify a raw payload (format, structure, checksum, provider
     * rules) and turn it into a reusable template.
     *
     * @throws UnsupportedPayloadException when the payload is not positively verified.
     */
    public function inspect(string $payload): ParsedPayload;

    /**
     * Build a dynamic payload from a stored template, changing only the fields
     * the specification allows (amount, reference, expiry, initiation method,
     * checksum). $expiresAt is used by formats that carry an expiry field.
     */
    public function build(array $template, string $amount, string $reference, ?DateTimeInterface $expiresAt = null): string;

    /**
     * Every data object of a payload (nested ones under "children") with its
     * name, what it does and what the gateway does with it on each payment.
     *
     * @return list<array{tag: string, name: string, description: string, value: ?string, present: bool, behaviour: string, children: list<array>}>
     */
    public function describe(string $payload): array;

    /**
     * Fields an admin may change on a stored template.
     *
     * @return array<string, array{label: string, max: int, required: bool, help: string}>
     */
    public function editableFields(): array;

    /**
     * Apply admin edits to a template and return the full sealed payload
     * (checksum recalculated). The caller re-verifies it with inspect().
     *
     * @param  array<string, ?string>  $values  tag => new value ('' / null removes an optional field)
     *
     * @throws UnsupportedPayloadException when a value is not allowed
     */
    public function applyEdits(array $template, array $values): string;
}
