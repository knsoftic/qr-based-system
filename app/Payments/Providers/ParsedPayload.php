<?php

namespace App\Payments\Providers;

/**
 * Result of inspecting an uploaded QR payload.
 *
 * - template: everything needed to build dynamic payloads later (encrypted at rest).
 * - summary:  safe-to-display description with masked identifiers (admin UI only).
 */
final class ParsedPayload
{
    public function __construct(
        public readonly string $format,
        public readonly array $template,
        public readonly array $summary,
    ) {
    }
}
