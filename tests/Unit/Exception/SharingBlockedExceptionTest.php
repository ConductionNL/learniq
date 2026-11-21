<?php

/**
 * Unit tests for SharingBlockedException: the gate's refusal as it reaches callers.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Exception;

use OCA\Learniq\Exception\SharingBlockedException;
use OCA\Learniq\Service\CourseSharingGate;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Exception\SharingBlockedException
 * @uses   \OCA\Learniq\Service\CourseSharingGate
 * @uses   \OCA\Learniq\Support\TextValue
 */
class SharingBlockedExceptionTest extends TestCase {

	/**
	 * The gate's reasons travel unchanged and in order, under a fixed message.
	 *
	 * @return void
	 */
	public function testCarriesTheGatesReasonsInOrder(): void {
		$blockers = (new CourseSharingGate())->check(
			course: ['id' => 'course-1', 'name' => 'Wiskunde', 'license' => 'All rights reserved'],
			lessons: [['id' => 'lesson-1', 'name' => 'Breuken', 'license' => 'proprietary']],
			materials: [],
			noPupilData: true,
			rightsCleared: false
		);
		self::assertNotSame([], $blockers);

		$exception = new SharingBlockedException(blockers: $blockers);

		self::assertSame($blockers, $exception->getBlockers());
		self::assertSame('This course may not leave the school yet.', $exception->getMessage());
		self::assertSame(0, $exception->getCode());
		self::assertNull($exception->getPrevious());
	}//end testCarriesTheGatesReasonsInOrder()

	/**
	 * Callers that catch a RuntimeException still see the refusal and its reasons.
	 *
	 * @return void
	 */
	public function testIsCaughtAsARuntimeExceptionWithItsReasons(): void {
		$blockers = [['code' => CourseSharingGate::AUTHOR_MISSING, 'id' => 'course-2', 'name' => 'Biologie']];

		try {
			throw new SharingBlockedException(blockers: $blockers);
		} catch (RuntimeException $e) {
			self::assertInstanceOf(SharingBlockedException::class, $e);
			self::assertSame($blockers, $e->getBlockers());
		}
	}//end testIsCaughtAsARuntimeExceptionWithItsReasons()

	/**
	 * A refusal without reasons answers an empty list, not null.
	 *
	 * @return void
	 */
	public function testAnEmptyRefusalHasNoReasons(): void {
		self::assertSame([], (new SharingBlockedException(blockers: []))->getBlockers());
	}//end testAnEmptyRefusalHasNoReasons()
}//end class
