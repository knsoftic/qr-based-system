<?php

namespace App\Payments\Providers;

use App\Payments\Exceptions\UnsupportedPayloadException;

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
     * the specification allows (amount, reference, initiation method, checksum).
     */
    public function build(array $template, string $amount, string $reference): string;
}
