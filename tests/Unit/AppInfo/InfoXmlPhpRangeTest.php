<?php
/**
 * The PHP range in appinfo/info.xml admits every PHP the supported Nextcloud
 * images run.
 *
 * Nextcloud's DependencyAnalyzer refuses `occ app:enable` when the running PHP
 * is above `max-version`, and `--force` does not skip that check. The
 * nextcloud:34 image runs PHP 8.5, so a ceiling of 8.4 made learniq
 * uninstallable there.
 *
 * @category Test
 * @package  OCA\Learniq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Reads the shipped info.xml.
 */
class InfoXmlPhpRangeTest extends TestCase
{
    /**
     * The PHP versions of the Nextcloud images learniq supports (32 to 35).
     *
     * @return array<string, array{string}>
     */
    public static function supportedPhpProvider(): array
    {
        return [
            'php 8.3' => ['8.3.16'],
            'php 8.4' => ['8.4.14'],
            'php 8.5 (nextcloud:34 image)' => ['8.5.12'],
        ];
    }//end supportedPhpProvider()

    /**
     * A supported PHP is inside the declared range.
     *
     * @param string $php A PHP version.
     *
     * @dataProvider supportedPhpProvider
     *
     * @return void
     */
    public function testTheDeclaredRangeAdmitsASupportedPhp(string $php): void
    {
        $info = new SimpleXMLElement((string) file_get_contents(__DIR__.'/../../../appinfo/info.xml'));
        $range = $info->dependencies->php;

        $min = (string) $range['min-version'];
        $max = (string) $range['max-version'];

        $this->assertNotSame('', $min);
        $this->assertTrue(version_compare($php, $min, '>='), "PHP $php is below min-version $min");
        if ($max !== '') {
            // DependencyAnalyzer compares against max-version cut to the same precision.
            $cut = implode('.', array_slice(explode('.', $php), 0, count(explode('.', $max))));
            $this->assertTrue(version_compare($cut, $max, '<='), "PHP $php is above max-version $max");
        }
    }//end testTheDeclaredRangeAdmitsASupportedPhp()
}//end class
