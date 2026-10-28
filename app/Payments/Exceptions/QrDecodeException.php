<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * The uploaded image could not be read as a QR code.
 */
class QrDecodeException extends RuntimeException
{
}
