<?php

namespace Tests\Unit;

use Fhp\Action\GetDepotAufstellung;
use Fhp\Segment\WPD\HIWPDSv6;
use Fhp\Segment\WPD\HIWPDv6;
use Fhp\Segment\WPD\HKWPDv6;
use PHPUnit\Framework\TestCase;

/**
 * Guards that the vendored HKWPD v6 patch (patches/nemiah-phpfints-hkwpd-v6.patch) is applied on
 * composer install. Without v6 the depot query fails at banks that announce HIWPDS only in v6
 * (e.g. DKB) with "not supported". If this test fails, the composer post-install patch step did not run.
 */
class FinTsDepotV6PatchTest extends TestCase
{
    public function test_the_hkwpd_v6_segment_classes_are_present(): void
    {
        $this->assertTrue(class_exists(HKWPDv6::class), 'HKWPDv6 missing – the nemiah/php-fints HKWPD v6 patch was not applied.');
        $this->assertTrue(class_exists(HIWPDv6::class), 'HIWPDv6 missing – the nemiah/php-fints HKWPD v6 patch was not applied.');
        $this->assertTrue(class_exists(HIWPDSv6::class), 'HIWPDSv6 missing – the nemiah/php-fints HKWPD v6 patch was not applied.');
    }

    public function test_the_depot_action_references_hkwpd_version_six(): void
    {
        $file = (new \ReflectionClass(GetDepotAufstellung::class))->getFileName();
        $this->assertIsString($file);
        $this->assertStringContainsString('HKWPDv6', (string) file_get_contents($file));
    }
}
