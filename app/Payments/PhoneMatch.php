<?php

namespace App\Payments;

/**
 * Matches a (possibly masked) mobile number shown in a payment SMS against the
 * number a customer entered at checkout. Providers mask numbers in many ways —
 * last digits (03XX*****67, ******4567), middle (0300***4567) or leading
 * (0300XXXXX..) — so matching is done position-by-position over the national
 * number, treating masked positions as wildcards.
 *
 * A full (unmasked) number must match exactly; a masked number must line up on
 * every visible digit, with at least MIN_KNOWN_DIGITS visible, so a few shown
 * digits (e.g. the last four) are enough to confirm the order.
 */
class PhoneMatch
{
    /** At least this many real digits must be visible to accept a masked match. */
    private const MIN_KNOWN_DIGITS = 4;

    /** Mask characters providers use for hidden digits. */
    private const MASK = 'xX*#•';

    public static function matches(string $claimed, string $body): bool
    {
        $claimedNat = self::toNational($claimed);
        if ($claimedNat === null) {
            return false;
        }

        foreach (self::tokens($body) as $slots) {
            if (self::slotsMatch($slots, $claimedNat)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any 03XXXXXXXXX / +923XXXXXXXXX / 3XXXXXXXXX form -> 10-digit national
     * "3XXXXXXXXX", or null if it is not a Pakistani mobile.
     */
    public static function toNational(?string $number): ?string
    {
        $d = preg_replace('/\D/', '', (string) $number);
        $d = preg_replace('/^92/', '', $d);
        $d = preg_replace('/^0/', '', $d);

        return (strlen($d) === 10 && $d[0] === '3') ? $d : null;
    }

    /**
     * Phone-like tokens from the body as "slot" strings over [0-9.] where '.'
     * is a masked digit, normalised to the national form where possible. Only
     * full mobiles or clearly masked, phone-length tokens are returned.
     *
     * @return list<string>
     */
    private static function tokens(string $body): array
    {
        $mask = preg_quote(self::MASK, '/');
        preg_match_all('/(?:\+?92|0)?['.$mask.'0-9]['.$mask.'0-9\- ]{3,15}['.$mask.'0-9]/u', $body, $m);

        $out = [];
        foreach ($m[0] as $raw) {
            $clean = preg_replace('/[^'.$mask.'0-9]/u', '', $raw);
            if ($clean === '') {
                continue;
            }

            // Strip a country / trunk prefix so what remains is the national part.
            if (strlen($clean) >= 12 && str_starts_with($clean, '92')) {
                $clean = substr($clean, 2);
            }
            if (strlen($clean) === 11 && $clean[0] === '0') {
                $clean = substr($clean, 1);
            }

            $masked = (bool) preg_match('/['.$mask.']/u', $clean);
            $slots = preg_replace('/['.$mask.']/u', '.', $clean);
            $known = strlen(preg_replace('/\D/', '', $clean));

            $isFullMobile = ! $masked && strlen($slots) === 10 && $slots[0] === '3';
            $isMaskedPhone = $masked && $known >= self::MIN_KNOWN_DIGITS && strlen($slots) >= 4 && strlen($slots) <= 11;

            if ($isFullMobile || $isMaskedPhone) {
                $out[] = $slots;
            }
        }

        return $out;
    }

    private static function slotsMatch(string $slots, string $claimedNat): bool
    {
        // Aligned national form: compare each position, masks are wildcards.
        if (strlen($slots) === 10) {
            $known = 0;
            $subscriberKnown = 0;
            for ($i = 0; $i < 10; $i++) {
                if ($slots[$i] === '.') {
                    continue;
                }
                if ($slots[$i] !== $claimedNat[$i]) {
                    return false;
                }
                $known++;
                if ($i >= 3) {
                    $subscriberKnown++; // digits past the operator code
                }
            }

            // Enough digits overall, and some beyond the operator prefix, so we
            // are not matching on the low-entropy "300"/"345" lead alone.
            return $known >= self::MIN_KNOWN_DIGITS && $subscriberKnown >= 2;
        }

        // Shorter (tail-only) masked token: match the trailing visible digits.
        $tail = '';
        for ($i = strlen($slots) - 1; $i >= 0 && $slots[$i] !== '.'; $i--) {
            $tail = $slots[$i].$tail;
        }

        return strlen($tail) >= self::MIN_KNOWN_DIGITS && str_ends_with($claimedNat, $tail);
    }
}
