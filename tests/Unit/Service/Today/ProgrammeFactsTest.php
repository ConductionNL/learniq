<?php

/**
 * What the BPV-begeleider's and the exam committee's First today rules count, and their empty cases.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/tasks.md#T5
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#T3
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Today;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\Today\ExamFacts;
use OCA\Learniq\Service\Today\PlacementFacts;
use OCA\Learniq\Service\Today\TodayContext;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Today\PlacementFacts
 * @covers \OCA\Learniq\Service\Today\ExamFacts
 * @uses   \OCA\Learniq\Service\Today\TodayContext
 */
class ProgrammeFactsTest extends TestCase {

	/**
	 * Monday 5 October 2026 at 8.00, Amsterdam.
	 *
	 * @return DateTimeImmutable
	 */
	private static function monday(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-05T08:00:00', new DateTimeZone('Europe/Amsterdam'));
	}//end monday()

	/**
	 * The coach Karin on Monday 5 October 2026.
	 *
	 * @return TodayContext
	 */
	private function karin(): TodayContext {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('karin');

		return new TodayContext(user: $user, now: self::monday());
	}//end karin()

	/**
	 * Daan Smits has no company while his BPV period runs; a student whose period has not started does not count.
	 *
	 * @return void
	 */
	public function testAStudentWithoutACompanyWhileThePeriodRuns(): void {
		$placements = [
			[
				'learnerRef' => 'daan-smits',
				'schoolCoachId' => 'karin',
				'phase' => 'searching',
				'periodFrom' => '2026-09-01',
				'periodTo' => '2027-01-29',
				'lateLabel' => '5 weken te laat',
			],
			['learnerRef' => 'later', 'schoolCoachId' => 'karin', 'phase' => 'searching', 'periodFrom' => '2027-02-01', 'periodTo' => '2027-06-30'],
			['learnerRef' => 'undated', 'schoolCoachId' => 'karin', 'phase' => 'searching'],
			['learnerRef' => 'jesse', 'schoolCoachId' => 'karin', 'phase' => 'running', 'periodFrom' => '2026-09-01'],
			['learnerRef' => 'other', 'schoolCoachId' => 'piet', 'phase' => 'searching', 'periodFrom' => '2026-09-01'],
		];

		self::assertSame(
			expected: ['count' => 1, 'learnerRef' => 'daan-smits', 'lateLabel' => '5 weken te laat'],
			actual: (new PlacementFacts())->placementWithoutCompany(placements: $placements, context: $this->karin())
		);
		self::assertSame(expected: 0, actual: (new PlacementFacts())->placementWithoutCompany(placements: [], context: $this->karin())['count']);
	}//end testAStudentWithoutACompanyWhileThePeriodRuns()

	/**
	 * Jesse Kok's visit has no date; a proposed or planned one does not count.
	 *
	 * @return void
	 */
	public function testAVisitWithoutADate(): void {
		$visits = [
			['id' => 'v-jesse', 'learnerRef' => 'jesse-kok', 'schoolCoachId' => 'karin', 'lifecycle' => 'unplanned'],
			['id' => 'v-milan', 'learnerRef' => 'milan', 'schoolCoachId' => 'karin', 'lifecycle' => 'planned'],
			['id' => 'v-asked', 'learnerRef' => 'aylin', 'schoolCoachId' => 'karin', 'lifecycle' => 'proposed'],
			['id' => 'v-other', 'learnerRef' => 'x', 'schoolCoachId' => 'piet', 'lifecycle' => 'unplanned'],
		];

		self::assertSame(
			expected: ['count' => 1, 'visitId' => 'v-jesse', 'learnerRef' => 'jesse-kok'],
			actual: (new PlacementFacts())->visitWithoutDate(visits: $visits, uid: 'karin')
		);
		self::assertSame(expected: 0, actual: (new PlacementFacts())->visitWithoutDate(visits: [], uid: 'karin')['count']);
	}//end testAVisitWithoutADate()

	/**
	 * Hours of the coach's students submitted more than seven days ago and still waiting.
	 *
	 * @return void
	 */
	public function testHoursWaitingLongerThanSevenDays(): void {
		$bpv = [
			'placements' => [['id' => 'p-milan', 'schoolCoachId' => 'karin'], ['id' => 'p-other', 'schoolCoachId' => 'piet']],
			'weeks' => [
				[
					'bpvPlacementId' => 'p-milan',
					'isoWeek' => '2026-W38',
					'hoursSubmitted' => 24,
					'submittedAt' => '2026-09-18T17:00:00+02:00',
					'lifecycle' => 'submitted',
				],
				[
					'bpvPlacementId' => 'p-milan',
					'isoWeek' => '2026-W39',
					'hoursSubmitted' => 16.5,
					'submittedAt' => '2026-09-25T17:00:00+02:00',
					'lifecycle' => 'submitted',
				],
				[
					'bpvPlacementId' => 'p-milan',
					'isoWeek' => '2026-W40',
					'hoursSubmitted' => 32,
					'submittedAt' => '2026-10-02T17:00:00+02:00',
					'lifecycle' => 'submitted',
				],
				[
					'bpvPlacementId' => 'p-milan',
					'isoWeek' => '2026-W37',
					'hoursSubmitted' => 32,
					'submittedAt' => '2026-09-11T17:00:00+02:00',
					'lifecycle' => 'approved',
				],
				[
					'bpvPlacementId' => 'p-other',
					'isoWeek' => '2026-W36',
					'hoursSubmitted' => 32,
					'submittedAt' => '2026-09-04T17:00:00+02:00',
					'lifecycle' => 'submitted',
				],
			],
		];

		self::assertSame(
			expected: ['count' => 2, 'hours' => 40.5, 'isoWeek' => '2026-W38'],
			actual: (new PlacementFacts())->hoursWaiting(bpv: $bpv, context: $this->karin())
		);
		self::assertSame(expected: 0, actual: (new PlacementFacts())->hoursWaiting(bpv: [], context: $this->karin())['count']);
	}//end testHoursWaitingLongerThanSevenDays()

	/**
	 * The coach's submitted hours per company, the longest waiting company first; approved weeks and other coaches' placements stay out.
	 *
	 * @return void
	 */
	public function testHoursPerCompanyTheLongestWaitingFirst(): void {
		$bpv = [
			'placements' => [
				['id' => 'p-milan', 'schoolCoachId' => 'karin', 'trainingCompanyName' => 'Van Dijk Machinebouw'],
				['id' => 'p-jesse', 'schoolCoachId' => 'karin', 'trainingCompanyName' => 'Van Dijk Machinebouw'],
				['id' => 'p-aylin', 'schoolCoachId' => 'karin', 'trainingCompanyName' => 'Hollandse Techniek'],
				['id' => 'p-other', 'schoolCoachId' => 'piet', 'trainingCompanyName' => 'Hollandse Techniek'],
			],
			'weeks' => [
				['bpvPlacementId' => 'p-milan', 'isoWeek' => '2026-W40', 'hoursSubmitted' => 32, 'submittedAt' => '2026-10-02T17:00:00+02:00', 'lifecycle' => 'submitted'],
				['bpvPlacementId' => 'p-jesse', 'isoWeek' => '2026-W40', 'hoursSubmitted' => 24, 'submittedAt' => '2026-10-02T18:00:00+02:00', 'lifecycle' => 'submitted'],
				['bpvPlacementId' => 'p-aylin', 'isoWeek' => '2026-W38', 'hoursSubmitted' => 16.5, 'submittedAt' => '2026-09-18T17:00:00+02:00', 'lifecycle' => 'submitted'],
				['bpvPlacementId' => 'p-aylin', 'isoWeek' => '2026-W37', 'hoursSubmitted' => 32, 'submittedAt' => '2026-09-11T17:00:00+02:00', 'lifecycle' => 'approved'],
				['bpvPlacementId' => 'p-other', 'isoWeek' => '2026-W36', 'hoursSubmitted' => 32, 'submittedAt' => '2026-09-04T17:00:00+02:00', 'lifecycle' => 'submitted'],
			],
		];

		self::assertSame(
			expected: [
				[
					'company' => 'Hollandse Techniek',
					'students' => 1,
					'weeks' => 1,
					'hours' => 16.5,
					'oldestSubmittedAt' => '2026-09-18T17:00:00+02:00',
					'oldestWeek' => '2026-W38',
				],
				[
					'company' => 'Van Dijk Machinebouw',
					'students' => 2,
					'weeks' => 2,
					'hours' => 56.0,
					'oldestSubmittedAt' => '2026-10-02T17:00:00+02:00',
					'oldestWeek' => '2026-W40',
				],
			],
			actual: (new PlacementFacts())->hoursPerCompany(bpv: $bpv, context: $this->karin())
		);
		self::assertSame(expected: [], actual: (new PlacementFacts())->hoursPerCompany(bpv: [], context: $this->karin()));
	}//end testHoursPerCompanyTheLongestWaitingFirst()

	/**
	 * The objection of 25 September, due on 9 October, is past half its term on 5 October; a decided one is not.
	 *
	 * @return void
	 */
	public function testAnObjectionPastHalfItsTerm(): void {
		$objections = [
			['id' => 'o-engels', 'lifecycle' => 'examiner-responded', 'submittedAt' => '2026-09-25T10:00:00+02:00', 'decideBy' => '2026-10-09'],
			['id' => 'o-fresh', 'lifecycle' => 'submitted', 'submittedAt' => '2026-10-02T10:00:00+02:00', 'decideBy' => '2026-10-16'],
			['id' => 'o-done', 'lifecycle' => 'decided', 'submittedAt' => '2026-09-01T10:00:00+02:00', 'decideBy' => '2026-09-15'],
			['id' => 'o-undated', 'lifecycle' => 'submitted', 'submittedAt' => '2026-09-01T10:00:00+02:00'],
		];

		self::assertSame(
			expected: ['count' => 1, 'objectionId' => 'o-engels', 'decideBy' => '2026-10-09', 'responded' => true],
			actual: (new ExamFacts())->objectionPastHalf(objections: $objections, now: self::monday())
		);
		self::assertSame(expected: 0, actual: (new ExamFacts())->objectionPastHalf(objections: [], now: self::monday())['count']);
	}//end testAnObjectionPastHalfItsTerm()

	/**
	 * Sitting results graded more than five working days ago and not established, by sitting.
	 *
	 * @return void
	 */
	public function testResultsWaitingToBeEstablished(): void {
		$results = [
			['examSittingId' => 's-engels', 'lifecycle' => 'published', 'gradedAt' => '2026-09-22T12:00:00+02:00'],
			['examSittingId' => 's-engels', 'lifecycle' => 'published', 'gradedAt' => '2026-09-22T12:00:00+02:00'],
			['examSittingId' => 's-rekenen', 'lifecycle' => 'published', 'gradedAt' => '2026-09-25T12:00:00+02:00'],
			['examSittingId' => 's-recent', 'lifecycle' => 'published', 'gradedAt' => '2026-09-29T12:00:00+02:00'],
			[
				'examSittingId' => 's-done',
				'lifecycle' => 'published',
				'gradedAt' => '2026-09-01T12:00:00+02:00',
				'establishedAt' => '2026-09-10T09:00:00+02:00',
			],
			['lifecycle' => 'published', 'gradedAt' => '2026-09-01T12:00:00+02:00'],
			['examSittingId' => 's-concept', 'lifecycle' => 'concept', 'gradedAt' => '2026-09-01T12:00:00+02:00'],
		];

		self::assertSame(
			expected: ['count' => 3, 'sittings' => 2],
			actual: (new ExamFacts())->resultsWaiting(results: $results, now: self::monday())
		);
		self::assertSame(expected: ['count' => 0, 'sittings' => 0], actual: (new ExamFacts())->resultsWaiting(results: [], now: self::monday()));
	}//end testResultsWaitingToBeEstablished()
}//end class
