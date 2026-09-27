<?php

/**
 * Unit tests for CourseStoreUrlGuard: it hands every registry URL to
 * OpenRegister's SSRF guard (the stub in tests/Stubs keeps the scheme and
 * literal private-address checks of the real one).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\CourseStore
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lesson-sharing-via-store-plane/tasks.md#task-3-publisher
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseStore;

use InvalidArgumentException;
use OCA\Learniq\Service\CourseStore\CourseStoreUrlGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\CourseStore\CourseStoreUrlGuard
 */
class CourseStoreUrlGuardTest extends TestCase {

	/**
	 * A public https address passes.
	 *
	 * @return void
	 */
	public function testAPublicAddressPasses(): void {
		(new CourseStoreUrlGuard())->assertSafe('https://93.184.216.34/index.php/apps/openregister/api/objects/learniq/shared-course-package');

		self::addToAssertionCount(1);
	}//end testAPublicAddressPasses()

	/**
	 * A private address is refused.
	 *
	 * @return void
	 */
	public function testAPrivateAddressIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		(new CourseStoreUrlGuard())->assertSafe('http://192.168.1.10/index.php');
	}//end testAPrivateAddressIsRefused()

	/**
	 * A non-http scheme is refused.
	 *
	 * @return void
	 */
	public function testANonHttpSchemeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		(new CourseStoreUrlGuard())->assertSafe('file:///etc/passwd');
	}//end testANonHttpSchemeIsRefused()
}//end class
