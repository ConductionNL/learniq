<?php

/**
 * Learniq self check-in register test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pins CheckInWindow's staff-only access and AttendanceRecord.markedVia.
 */
class SelfCheckInRegisterTest extends TestCase {

	/**
	 * The shipped schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas'];
	}//end schemas()

	/**
	 * Only staff read, create and update a window; no learner entry; no code
	 * is stored on it.
	 *
	 * @return void
	 */
	public function testAWindowIsStaffOnlyAndHoldsNoCode(): void {
		$window = $this->schemas()['CheckInWindow'];

		foreach (['read', 'create', 'update'] as $verb) {
			self::assertSame(['instructors', 'compliance-officers'], $window['authorization'][$verb], $verb);
		}

		self::assertSame('check-in-window', $window['slug']);
		self::assertSame(['rotating-qr', 'link'], $window['properties']['mode']['enum']);
		self::assertArrayNotHasKey('code', $window['properties']);
		self::assertSame(['from' => 'open', 'to' => 'closed'], array_intersect_key($window['x-openregister-lifecycle']['transitions']['close'], ['from' => 1, 'to' => 1]));
	}//end testAWindowIsStaffOnlyAndHoldsNoCode()

	/**
	 * A record says whether a teacher or a self check-in marked it; old rows
	 * read as teacher; learners still cannot write records.
	 *
	 * @return void
	 */
	public function testARecordSaysHowItWasMarked(): void {
		$record = $this->schemas()['AttendanceRecord'];

		self::assertSame(['teacher', 'self-check-in'], $record['properties']['markedVia']['enum']);
		self::assertSame('teacher', $record['properties']['markedVia']['default']);
		self::assertSame(['instructors', 'compliance-officers'], $record['authorization']['create']);
	}//end testARecordSaysHowItWasMarked()
}//end class
