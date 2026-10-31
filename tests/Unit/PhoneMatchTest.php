<?php

namespace Tests\Unit;

use App\Payments\PhoneMatch;
use PHPUnit\Framework\TestCase;

class PhoneMatchTest extends TestCase
{
    public function test_full_numbers_match_in_any_common_format(): void
    {
        $this->assertTrue(PhoneMatch::matches('03001234567', 'received from 0300-1234567'));
        $this->assertTrue(PhoneMatch::matches('03001234567', 'from +923001234567 Ali'));
        $this->assertTrue(PhoneMatch::matches('03001234567', 'from 3001234567'));
        $this->assertFalse(PhoneMatch::matches('03001234567', 'from 03009999999'));
    }

    public function test_masked_last_digits_match(): void
    {
        $this->assertTrue(PhoneMatch::matches('03001234567', 'received from ******4567'));
        $this->assertTrue(PhoneMatch::matches('03001234567', 'received from 0300***4567'));
        $this->assertTrue(PhoneMatch::matches('03001234567', 'received from 0300xxxxx67'));

        $this->assertFalse(PhoneMatch::matches('03001234567', 'received from ******9999'));
        $this->assertFalse(PhoneMatch::matches('03001234567', 'received from 0300***9999'));
    }

    public function test_masked_middle_and_operator_prefix(): void
    {
        $this->assertTrue(PhoneMatch::matches('03451119999', 'from 0345***9999'));
        // Same last digits but a different operator code must not match.
        $this->assertFalse(PhoneMatch::matches('03451119999', 'from 0300***9999'));
    }

    public function test_amounts_references_and_short_numbers_do_not_match(): void
    {
        $this->assertFalse(PhoneMatch::matches('03001234567', 'Rs. 1,500.00 received. TID 556677. Bal Rs 4,320.00'));
        $this->assertFalse(PhoneMatch::matches('03001234567', 'only 67 shown here'));
    }

    public function test_a_masked_card_number_is_not_treated_as_the_phone(): void
    {
        // 16-digit masked card ending in the same 4 digits must not match.
        $this->assertFalse(PhoneMatch::matches('03001234567', 'Card 1234-********-4567 was charged'));
    }

    public function test_non_pakistani_or_junk_claimed_never_matches(): void
    {
        $this->assertFalse(PhoneMatch::matches('12345', 'from 0300-1234567'));
    }
}
