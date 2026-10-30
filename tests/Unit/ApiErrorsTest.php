<?php

namespace Tests\Unit;

use App\Support\ApiErrors;
use PHPUnit\Framework\TestCase;

class ApiErrorsTest extends TestCase
{
    public function test_known_codes_are_registered(): void
    {
        // Codes the code base actually throws must be documented here (the docs
        // render from this registry), so keep these in sync if you add more.
        foreach ([
            'ip_not_allowed', 'insufficient_balance', 'payout_disabled', 'invalid_method',
            'amount_out_of_range', 'no_account_available', 'idempotency_conflict', 'rate_limited',
            'invalid_signature', 'ambiguous_match', 'no_match',
        ] as $code) {
            $this->assertTrue(ApiErrors::has($code), "Missing API error code: {$code}");
        }
    }

    public function test_merchant_scope_excludes_verifier_only_codes(): void
    {
        $merchant = ApiErrors::forScope('merchant');

        $this->assertArrayHasKey('ip_not_allowed', $merchant);
        $this->assertArrayHasKey('validation_error', $merchant); // "both"
        $this->assertArrayNotHasKey('amount_mismatch', $merchant); // verifier only
    }

    public function test_verifier_scope_includes_shared_and_verifier_codes(): void
    {
        $verifier = ApiErrors::forScope('verifier');

        $this->assertArrayHasKey('no_match', $verifier);
        $this->assertArrayHasKey('rate_limited', $verifier); // "both"
        $this->assertArrayNotHasKey('ip_not_allowed', $verifier); // merchant only
    }

    public function test_rows_are_ordered_by_http_status(): void
    {
        $statuses = array_map(fn ($row) => $row['status'], ApiErrors::forScope('merchant'));

        $sorted = $statuses;
        sort($sorted);
        $this->assertSame($sorted, array_values($statuses));
    }
}
