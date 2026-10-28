<?php

namespace App\Payments\Emv;

/**
 * CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF), as required by EMVCo
 * Merchant Presented Mode for data object "63".
 */
final class Crc16
{
    public static function checksum(string $data): string
    {
        $crc = 0xFFFF;
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return sprintf('%04X', $crc);
    }

    /**
     * Append "6304" + checksum to a payload that has no CRC yet.
     */
    public static function seal(string $payloadWithoutCrc): string
    {
        $data = $payloadWithoutCrc.'6304';

        return $data.self::checksum($data);
    }

    /**
     * True when the payload ends with a "63" data object holding the correct checksum.
     */
    public static function isValid(string $payload): bool
    {
        if (strlen($payload) < 8 || substr($payload, -8, 4) !== '6304') {
            return false;
        }

        return strtoupper(substr($payload, -4)) === self::checksum(substr($payload, 0, -4));
    }
}
