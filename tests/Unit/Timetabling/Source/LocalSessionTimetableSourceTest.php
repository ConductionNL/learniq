<?php

/**
 * Tests for LocalSessionTimetableSource.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Timetabling\Source
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-visibility-rules/specs/personal-timetable/spec.md#requirement-the-api-follows-the-same-line
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Timetabling\Source;

use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Session is staff-only in the object API, so the timetable endpoints read it
 * without the caller's RBAC, per cohort and never beyond the asked cohort.
 */
class LocalSessionTimetableSourceTest extends TestCase {

	/**
	 * Every read skips the caller's RBAC and stays inside the asked cohort or cover.
	 *
	 * @return void
	 */
	public function testReadsWithoutCallerRbacInsideTheAskedScope(): void {
		$calls = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config, bool $_rbac = true) use (&$calls): array {
				$calls[] = $_rbac;
				return OrEntityFactory::makeMany(
					[
						['id' => 's-1', 'cohortId' => 'c-1', 'substituteTeacherId' => 'eva'],
						['id' => 's-2', 'cohortId' => 'c-2', 'substituteTeacherId' => 'jan'],
					],
					'session'
				);
			}
		);
		$source = new LocalSessionTimetableSource($objects);

		self::assertSame(['s-1'], array_column($source->sessionsForCohorts(cohortIds: ['c-1'], from: null, to: null), 'id'));
		self::assertSame(['s-1'], array_column($source->sessionsForTeacher(userId: 'eva', from: null, to: null), 'id'));
		self::assertSame([false, false], $calls);
	}//end testReadsWithoutCallerRbacInsideTheAskedScope()
}//end class
