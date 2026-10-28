<?php

namespace App\Payments\Raast;

/**
 * Pakistani IBAN: "PK" + 2 check digits + 4-letter bank code + 16 alphanumerics,
 * verified with the ISO 13616 mod-97 check.
 */
final class Iban
{
    public static function isValid(string $iban): bool
    {
        return preg_match('/^PK\d{2}[A-Z]{4}[A-Z0-9]{16}$/', $iban) === 1
            && self::mod97(substr($iban, 4).substr($iban, 0, 4)) === 1;
    }

    /**
     * Build an IBAN with correct check digits (used for samples and tests).
     */
    public static function make(string $bban, string $country = 'PK'): string
    {
        $check = 98 - self::mod97($bban.$country.'00');

        return $country.str_pad((string) $check, 2, '0', STR_PAD_LEFT).$bban;
    }

    public static function bankCode(string $iban): string
    {
        return substr($iban, 4, 4);
    }

    public static function mask(string $iban): string
    {
        return substr($iban, 0, 4).str_repeat('•', strlen($iban) - 8).substr($iban, -4);
    }

    private static function mod97(string $value): int
    {
        $digits = preg_replace_callback('/[A-Z]/', fn (array $m) => (string) (ord($m[0]) - 55), $value);
        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder;
    }
}
