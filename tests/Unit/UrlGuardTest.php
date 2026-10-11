<?php

namespace Tests\Unit;

use App\Support\Security\UrlGuard;
use PHPUnit\Framework\TestCase;

class UrlGuardTest extends TestCase
{
    public function test_shape_requires_http_s_a_host_and_no_inline_credentials(): void
    {
        $this->assertTrue(UrlGuard::hasValidShape('https://fints.dkb.de/fints'));
        $this->assertFalse(UrlGuard::hasValidShape('ftp://fints.dkb.de/fints'));
        $this->assertFalse(UrlGuard::hasValidShape('https:///fints'));
        $this->assertFalse(UrlGuard::hasValidShape('https://user:pass@fints.dkb.de/fints'));
    }

    public function test_public_host_rejects_loopback_link_local_private_and_reserved_ranges(): void
    {
        $this->assertTrue(UrlGuard::isPublicHost('93.184.216.34'));
        $this->assertFalse(UrlGuard::isPublicHost('127.0.0.1'));
        $this->assertFalse(UrlGuard::isPublicHost('169.254.1.1'));
        $this->assertFalse(UrlGuard::isPublicHost('192.168.1.1'));
        $this->assertFalse(UrlGuard::isPublicHost('10.0.0.1'));
        $this->assertFalse(UrlGuard::isPublicHost('0.0.0.0'));
        $this->assertFalse(UrlGuard::isPublicHost('::1'));
    }

    /**
     * M7: decimal IP notation used to slip through because filter_var() rejects it as an IP,
     * gethostbynamel() then resolves no addresses at all, and the old code treated "nothing
     * resolved" as "nothing to block" instead of refusing it.
     */
    public function test_a_host_that_resolves_to_no_address_is_rejected_not_let_through(): void
    {
        $this->assertFalse(UrlGuard::isPublicHost('2130706433'));
        $this->assertFalse(UrlGuard::isPublicHost('this-host-does-not-exist.invalid'));
        $this->assertFalse(UrlGuard::isNotLocalHost('2130706433'));
        $this->assertFalse(UrlGuard::isNotLocalHost('this-host-does-not-exist.invalid'));
    }

    /**
     * M7: only exact "::1" was blocked for IPv6, not mapped forms like "::ffff:127.0.0.1".
     */
    public function test_ipv4_mapped_ipv6_loopback_is_rejected(): void
    {
        $this->assertFalse(UrlGuard::isPublicHost('::ffff:127.0.0.1'));
        $this->assertFalse(UrlGuard::isNotLocalHost('::ffff:127.0.0.1'));
        $this->assertFalse(UrlGuard::isNotLocalHost('::ffff:169.254.1.1'));
    }

    public function test_not_local_host_still_allows_a_private_lan_address(): void
    {
        $this->assertTrue(UrlGuard::isNotLocalHost('192.168.1.1'));
        $this->assertTrue(UrlGuard::isNotLocalHost('10.0.0.5'));
        $this->assertFalse(UrlGuard::isNotLocalHost('127.0.0.1'));
        $this->assertFalse(UrlGuard::isNotLocalHost('169.254.1.1'));
        $this->assertFalse(UrlGuard::isNotLocalHost('0.0.0.0'));
        $this->assertFalse(UrlGuard::isNotLocalHost('::1'));
    }
}
