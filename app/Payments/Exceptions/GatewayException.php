<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * Business error returned to API clients as
 * {"error": {"code": ..., "message": ...}} with the given HTTP status.
 */
class GatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }

    public static function noAccountAvailable(): self
    {
        return new self('No payment account is available right now. Please try again shortly.', 'no_account_available', 503);
    }

    public static function payInDisabled(): self
    {
        return new self('Pay-in is currently disabled.', 'payin_disabled', 503);
    }

    public static function idempotencyConflict(): self
    {
        return new self('This Idempotency-Key was already used with a different request body.', 'idempotency_conflict', 409);
    }
}
