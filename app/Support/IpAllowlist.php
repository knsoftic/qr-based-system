<?php

namespace App\Support;

/**
 * Parsing and matching for merchant IP allowlists. Rules are individual IPs
 * (IPv4 or IPv6) or CIDR ranges ("203.0.113.0/24", "2001:db8::/32"), one per
 * line. Matching is binary (inet_pton) so it is exact and family-aware.
 */
class IpAllowlist
{
    /**
     * Split stored text (newline- or comma-separated) into clean, de-duplicated rules.
     *
     * @return list<string>
     */
    public static function parse(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $rules = [];
        foreach (preg_split('/[\r\n,]+/', $text) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $rules[$part] = $part; // key de-dupes
            }
        }

        return array_values($rules);
    }

    /**
     * Normalise a textarea value to a canonical, one-per-line string (or null when empty).
     */
    public static function normalise(?string $text): ?string
    {
        $rules = self::parse($text);

        return $rules === [] ? null : implode("\n", $rules);
    }

    public static function isValidRule(string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '') {
            return false;
        }

        if (! str_contains($rule, '/')) {
            return filter_var($rule, FILTER_VALIDATE_IP) !== false;
        }

        [$subnet, $bits] = explode('/', $rule, 2);

        if (filter_var($subnet, FILTER_VALIDATE_IP) === false || ! ctype_digit($bits)) {
            return false;
        }

        $max = str_contains($subnet, ':') ? 128 : 32;

        return (int) $bits >= 0 && (int) $bits <= $max;
    }

    /**
     * @param  list<string>  $rules
     */
    public static function matches(string $ip, array $rules): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        foreach ($rules as $rule) {
            if (self::ruleMatches($ipBin, $rule)) {
                return true;
            }
        }

        return false;
    }

    private static function ruleMatches(string $ipBin, string $rule): bool
    {
        if (! str_contains($rule, '/')) {
            $ruleBin = @inet_pton(trim($rule));

            return $ruleBin !== false && strlen($ruleBin) === strlen($ipBin) && hash_equals($ruleBin, $ipBin);
        }

        [$subnet, $bits] = explode('/', $rule, 2);
        $subnetBin = @inet_pton(trim($subnet));

        if ($subnetBin === false || ! ctype_digit($bits) || strlen($subnetBin) !== strlen($ipBin)) {
            return false;
        }

        $bits = (int) $bits;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($wholeBytes > 0 && ! hash_equals(substr($subnetBin, 0, $wholeBytes), substr($ipBin, 0, $wholeBytes))) {
            return false;
        }

        if ($remainder !== 0) {
            $mask = 0xFF << (8 - $remainder) & 0xFF;

            if ((ord($ipBin[$wholeBytes]) & $mask) !== (ord($subnetBin[$wholeBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
