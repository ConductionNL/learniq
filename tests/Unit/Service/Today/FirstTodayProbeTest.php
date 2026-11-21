<?php

/**
 * The probe reads each rule's rows once, and knows no rule it was not given.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Today;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\Today\FirstTodayProbe;
use OCA\Learniq\Service\Today\FirstTodayReader;
use OCA\Learniq\Service\Today\TodayContext;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Today\FirstTodayProbe
 * @uses   \OCA\Learniq\Service\Today\RegisterFacts
 * @uses   \OCA\Learniq\Service\Today\AttendanceFacts
 * @uses   \OCA\Learniq\Service\Today\FollowUpFacts
 * @uses   \OCA\Learniq\Service\Today\PlacementFacts
 * @uses   \OCA\Learniq\Service\Today\ExamFacts
 * @uses   \OCA\Learniq\Service\Today\TodayContext
 */
class FirstTodayProbeTest extends TestCase {

	/**
	 * Daan on Monday 5 October 2026 at 10.30.
	 *
	 * @return TodayContext
	 */
	private function daan(): TodayContext {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('daan');

		return new TodayContext(user: $user, now: new DateTimeImmutable('2026-10-05T10:30:00', new DateTimeZone('Europe/Amsterdam')));
	}//end daan()

	/**
	 * The register rules and the hand-ins rule share one read of today's groups.
	 *
	 * @return void
	 */
	public function testTodaysGroupsAreReadOncePerKind(): void {
		$reader = $this->createMock(originalClassName: FirstTodayReader::class);
		$reader->expects(self::once())->method('groupsToday')->willReturn([]);
		$reader->method('rows')->willReturn([]);
		$probe = new FirstTodayProbe(reader: $reader);
		$context = $this->daan();

		self::assertSame(expected: 0, actual: $probe->facts(rule: 'lesson-register-open', context: $context)['count']);
		self::assertSame(expected: 0, actual: $probe->facts(rule: 'register-not-filled', context: $context)['count']);
		self::assertSame(expected: 0, actual: $probe->facts(rule: 'missing-hand-ins', context: $context)['count']);
	}//end testTodaysGroupsAreReadOncePerKind()

	/**
	 * A rule no role reads gives nothing and reads nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownRuleReadsNothing(): void {
		$reader = $this->createMock(originalClassName: FirstTodayReader::class);
		$reader->expects(self::never())->method('rows');
		$reader->expects(self::never())->method('groupsToday');

		self::assertNull(actual: (new FirstTodayProbe(reader: $reader))->facts(rule: 'no-such-rule', context: $this->daan()));
	}//end testAnUnknownRuleReadsNothing()
}//end class
