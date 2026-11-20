<?php

/**
 * Learniq leave request example set tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The primary set has two open leave requests, the secondary set two for the bovenbouw.
 */
class LeaveRequestExampleSetsTest extends TestCase {

	/**
	 * The seed objects of one example set.
	 *
	 * @param string $set The set id.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function objects(string $set): array {
		$file = dirname(__DIR__, 3) . '/lib/Settings/profiles/' . $set . '.json';

		return json_decode((string)file_get_contents($file), true)['x-openregister']['seedData']['objects'];
	}//end objects()

	/**
	 * Which classes each set's requests come from.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function sets(): array {
		return [
			'po' => ['po', ['Groep 7', 'Groep 4']],
			'vo bovenbouw' => ['vo', ['4H1', '5V1']],
		];
	}//end sets()

	/**
	 * Each request waits for the school, is asked by a guardian of its pupil, is read by the pupil's
	 * group teachers and fits what the school decides itself.
	 *
	 * @param string             $set     The set id.
	 * @param array<int, string> $classes The class of each request, in order.
	 *
	 * @return void
	 *
	 * @dataProvider sets
	 */
	public function testTheOpenRequestsBelongToTheirPupilsAndWaitForTheSchool(string $set, array $classes): void {
		$objects  = self::objects(set: $set);
		$profiles = array_column($objects['learner-profile'], null, 'uuid');
		$cohorts  = array_column($objects['cohort'], null, 'name');
		$requests = $objects['leave-request'];

		self::assertCount(2, $requests);
		foreach ($requests as $index => $request) {
			$pupil  = $profiles[$request['learnerRef']];
			$cohort = $cohorts[$classes[$index]];

			self::assertSame('submitted', $request['lifecycle']);
			self::assertContains($pupil['ncUserId'], $cohort['learnerIds']);
			self::assertContains($request['requestedByRef'], $pupil['guardianRefs']);
			self::assertArrayHasKey($request['requestedByRef'], $profiles);
			self::assertSame($cohort['teacherIds'], $request['teacherIds']);
			self::assertLessThanOrEqual(10, $request['schoolDays']);
			self::assertGreaterThan(0, $request['schoolDays']);
			self::assertLessThan($request['dateFrom'], $request['decideBy']);
			self::assertLessThanOrEqual($request['dateTo'], $request['dateFrom']);
			self::assertNotSame('', trim($request['reason']));
		}

		// Oldest first: the first request's deadline comes first.
		self::assertLessThan($requests[1]['decideBy'], $requests[0]['decideBy']);
	}//end testTheOpenRequestsBelongToTheirPupilsAndWaitForTheSchool()
}//end class
