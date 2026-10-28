<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * The QR was read, but its payload could not be positively identified and
 * verified by the selected provider. Such payloads are never modified.
 */
class UnsupportedPayloadException extends RuntimeException
{
}
