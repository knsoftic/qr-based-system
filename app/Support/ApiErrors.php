<?php

namespace App\Support;

/**
 * Single source of truth for API error codes. The docs render their error
 * tables from this list, so adding or changing a code here updates the public
 * documentation automatically — no separate doc edit needed.
 *
 * Shape: code => [httpStatus, 'meaning', scope]. Scope is 'merchant' (merchant
 * API), 'verifier' (verification API) or 'both'.
 */
class ApiErrors
{
    public const CODES = [
        'idempotency_key_required' => [400, 'Missing or invalid Idempotency-Key header', 'merchant'],
        'unauthenticated' => [401, 'Authentication failed (missing/invalid key)', 'both'],
        'invalid_signature' => [401, 'The X-Signature did not match', 'merchant'],
        'timestamp_out_of_range' => [401, 'X-Timestamp is outside the allowed window', 'merchant'],
        'replayed_request' => [401, 'This signature was already used (replay)', 'merchant'],
        'ip_not_allowed' => [403, 'Request IP is not in your allowlist for this side', 'merchant'],
        'merchant_disabled' => [403, 'The merchant account is disabled', 'merchant'],
        'not_found' => [404, 'Unknown payment or payout', 'both'],
        'no_match' => [404, 'No payment matches the reference/amount', 'verifier'],
        'idempotency_conflict' => [409, 'Same Idempotency-Key used with a different body', 'merchant'],
        'amount_mismatch' => [409, 'Amount does not match the payment', 'verifier'],
        'ambiguous_match' => [409, 'More than one payment matches; be more specific', 'verifier'],
        'verification_window_closed' => [409, 'Payment can no longer be auto-verified', 'verifier'],
        'not_verifiable' => [409, 'Payment status cannot be verified', 'verifier'],
        'validation_error' => [422, 'Invalid input (see fields)', 'both'],
        'invalid_method' => [422, 'Unsupported payout method', 'merchant'],
        'amount_out_of_range' => [422, 'Amount is outside the configured min/max', 'merchant'],
        'insufficient_balance' => [422, 'Withdrawal exceeds your available balance', 'merchant'],
        'rate_limited' => [429, 'Too many requests; retry later', 'both'],
        'no_account_available' => [503, 'No payment account can accept right now', 'merchant'],
        'payin_disabled' => [503, 'Pay-in is disabled', 'merchant'],
        'payout_disabled' => [503, 'Pay-out is disabled', 'merchant'],
        'verification_disabled' => [503, 'Verification API is disabled', 'verifier'],
    ];

    public static function has(string $code): bool
    {
        return array_key_exists($code, self::CODES);
    }

    /**
     * Codes for a scope ('merchant' or 'verifier'; 'both' always included),
     * ordered by HTTP status then code.
     *
     * @return array<string, array{status: int, meaning: string}>
     */
    public static function forScope(string $scope): array
    {
        $rows = [];
        foreach (self::CODES as $code => [$status, $meaning, $codeScope]) {
            if ($codeScope === $scope || $codeScope === 'both') {
                $rows[$code] = ['status' => $status, 'meaning' => $meaning];
            }
        }

        uasort($rows, fn ($a, $b) => [$a['status']] <=> [$b['status']]);

        return $rows;
    }
}
