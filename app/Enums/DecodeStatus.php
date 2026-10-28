<?php

namespace App\Enums;

enum DecodeStatus: string
{
    // QR uploaded, not processed yet.
    case Pending = 'pending';
    // Payload read, format identified and checksum verified.
    case Decoded = 'decoded';
    // Image could not be read as a QR code.
    case Failed = 'failed';
    // QR read fine, but no provider adapter recognises / verifies the payload.
    case Unsupported = 'unsupported';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
