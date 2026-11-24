<?php

/**
 * Unit tests for CohortSyncLine and its controller.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service\Cohort
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Cohort;

use DateTimeZone;
use OCA\Learniq\Controller\CohortSyncLineController;
use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Cohort\CohortSyncLine;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The groups list says when, from where and with how many changes it was last synced.
 *
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
 */
class CohortSyncLineTest extends TestCase {

	/**
	 * The board's groups: two synced by the 07.00 run, one by an earlier run, one by hand.
	 */
	private const COHORTS = [
		['name' => '3HV-B', 'externalSource' => 'magister', 'syncedAt' => '2026-10-05T05:00:12+00:00', 'syncChanges' => 2],
		['name' => '3HV-A', 'externalSource' => 'magister', 'syncedAt' => '2026-10-05T05:00:40+00:00', 'syncChanges' => 1],
		['name' => '2HV-C', 'externalSource' => 'magister', 'syncedAt' => '2026-10-04T05:00:00+00:00', 'syncChanges' => 4],
		['name' => 'Huiswerkklas'],
	];

	/**
	 * The last run is the newest minute of one source, with the changes of the groups it wrote.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
	 */
	public function testTheLastRunCountsTheChangesOfTheGroupsItWrote(): void {
		$words = new CohortSyncLine();
		$run   = $words->lastRun(cohorts: self::COHORTS);

		self::assertSame(expected: ['magister', 3], actual: [$run['source'], $run['changes']]);
		self::assertSame(expected: 'Magister', actual: $words->sourceName(source: $run['source']));
		self::assertSame(expected: '07.00', actual: $words->timeOf(at: $run['at'], zone: new DateTimeZone('Europe/Amsterdam')));
		self::assertSame(expected: 'Parnassys', actual: $words->sourceName(source: 'parnassys'));
	}//end testTheLastRunCountsTheChangesOfTheGroupsItWrote()

	/**
	 * No synced group, or only unreadable moments: no run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
	 */
	public function testNoRunWithoutASyncedGroup(): void {
		$words = new CohortSyncLine();

		self::assertNull(actual: $words->lastRun(cohorts: []));
		self::assertNull(actual: $words->lastRun(cohorts: [['name' => 'x'], ['externalSource' => 'magister', 'syncedAt' => 'not a time'], ['syncedAt' => '2026-10-05T05:00:00+00:00']]));
	}//end testNoRunWithoutASyncedGroup()

	/**
	 * The endpoint answers the line over the caller's own groups, '' without a synced one, 401 signed out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
	 */
	public function testTheEndpointWordsTheLineOverTheCallersGroups(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturnOnConsecutiveCalls($user, $user, null);
		$access = $this->createMock(originalClassName: RollCallAccess::class);
		$access->method('cohortsFor')->willReturnOnConsecutiveCalls(self::COHORTS, [['name' => 'x']]);
		$access->method('zone')->willReturn(new DateTimeZone('Europe/Amsterdam'));
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('n')->willReturnCallback(
			static fn (string $one, string $many, int $count, array $parameters): string => vsprintf(($count === 1 ? $one : $many), $parameters)
		);
		$l10n->method('t')->willReturnArgument(0);

		$controller = new CohortSyncLineController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $session,
			access: $access,
			logger: new NullLogger(),
			l10n: $l10n
		);

		self::assertSame(expected: ['line' => 'Updated from Magister at 07.00, 3 changes'], actual: $controller->show()->getData());
		self::assertSame(expected: ['line' => ''], actual: $controller->show()->getData());
		self::assertSame(expected: 401, actual: $controller->show()->getStatus());
	}//end testTheEndpointWordsTheLineOverTheCallersGroups()
}//end class
