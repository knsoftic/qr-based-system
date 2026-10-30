<?php

namespace Tests\Unit;

use App\Support\IpAllowlist;
use PHPUnit\Framework\TestCase;

class IpAllowlistTest extends TestCase
{
    public function test_parse_splits_cleans_and_dedupes(): void
    {
        $this->assertSame([], IpAllowlist::parse(null));
        $this->assertSame([], IpAllowlist::parse("  \n  "));
        $this->assertSame(
            ['203.0.113.5', '10.0.0.0/8'],
            IpAllowlist::parse(" 203.0.113.5 \n10.0.0.0/8\n203.0.113.5 , ")
        );
    }

    public function test_normalise_returns_canonical_lines_or_null(): void
    {
        $this->assertNull(IpAllowlist::normalise(''));
        $this->assertSame("203.0.113.5\n10.0.0.0/8", IpAllowlist::normalise("203.0.113.5, 10.0.0.0/8"));
    }

    public function test_rule_validation(): void
    {
        $this->assertTrue(IpAllowlist::isValidRule('203.0.113.5'));
        $this->assertTrue(IpAllowlist::isValidRule('203.0.113.0/24'));
        $this->assertTrue(IpAllowlist::isValidRule('2001:db8::/32'));
        $this->assertTrue(IpAllowlist::isValidRule('::1'));

        $this->assertFalse(IpAllowlist::isValidRule('not-an-ip'));
        $this->assertFalse(IpAllowlist::isValidRule('203.0.113.5/33'));   // v4 mask too big
        $this->assertFalse(IpAllowlist::isValidRule('2001:db8::/129'));   // v6 mask too big
        $this->assertFalse(IpAllowlist::isValidRule('203.0.113.5/'));
        $this->assertFalse(IpAllowlist::isValidRule(''));
    }

    public function test_exact_match(): void
    {
        $this->assertTrue(IpAllowlist::matches('203.0.113.5', ['203.0.113.5']));
        $this->assertFalse(IpAllowlist::matches('203.0.113.6', ['203.0.113.5']));
        // An IPv4 does not match an IPv6 rule of the "same" textual form.
        $this->assertFalse(IpAllowlist::matches('203.0.113.5', ['::1']));
    }

    public function test_cidr_match_ipv4(): void
    {
        $rules = ['203.0.113.0/24'];
        $this->assertTrue(IpAllowlist::matches('203.0.113.1', $rules));
        $this->assertTrue(IpAllowlist::matches('203.0.113.254', $rules));
        $this->assertFalse(IpAllowlist::matches('203.0.114.1', $rules));

        // Odd prefix length (not byte-aligned).
        $this->assertTrue(IpAllowlist::matches('10.1.2.3', ['10.1.2.0/28']));
        $this->assertFalse(IpAllowlist::matches('10.1.2.17', ['10.1.2.0/28']));
    }

    public function test_cidr_match_ipv6(): void
    {
        $this->assertTrue(IpAllowlist::matches('2001:db8::1', ['2001:db8::/32']));
        $this->assertFalse(IpAllowlist::matches('2001:dead::1', ['2001:db8::/32']));
    }

    public function test_invalid_ip_never_matches(): void
    {
        $this->assertFalse(IpAllowlist::matches('garbage', ['0.0.0.0/0']));
        $this->assertFalse(IpAllowlist::matches('203.0.113.5', []));
    }
}
