<?php

/**
 * The First today card of a user: her role, the rule that wins, and the reads it makes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Today;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Kernel\Contracts\DashboardRoleServiceInterface;
use OCA\Learniq\Kernel\Contracts\SegmentServiceInterface;
use OCA\Learniq\Service\Today\FirstTodayReader;
use OCA\Learniq\Service\Today\FirstTodayService;
use OCA\Learniq\Service\Today\TodayContext;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Service\Today\FirstTodayService
 * @uses   \OCA\Learniq\Service\Today\FirstTodayRules
 * @uses   \OCA\Learniq\Service\Today\FirstTodayProbe
 * @uses   \OCA\Learniq\Service\Today\RegisterFacts
 * @uses   \OCA\Learniq\Service\Today\AttendanceFacts
 * @uses   \OCA\Learniq\Service\Today\FollowUpFacts
 * @uses   \OCA\Learniq\Service\Today\TodayContext
 * @uses   \OCA\Learniq\Service\Today\PlacementFacts
 * @uses   \OCA\Learniq\Service\Today\ExamFacts
 */
class FirstTodayServiceTest extends TestCase {

	/**
	 * The reader double.
	 *
	 * @var FirstTodayReader&MockObject
	 */
	private FirstTodayReader&MockObject $reader;

	/**
	 * The service for a user of a role at a school kind, on Monday 5 October 2026 at a time.
	 *
	 * @param string $role    The primary role.
	 * @param string $segment The school kind.
	 * @param string $time    `H:i`, Amsterdam.
	 *
	 * @return FirstTodayService
	 */
	private function service(string $role, string $segment, string $time='08:40'): FirstTodayService {
		$roles = $this->createMock(originalClassName: DashboardRoleServiceInterface::class);
		$roles->method('resolvePrimaryRole')->willReturn($role);
		$segments = $this->createMock(originalClassName: SegmentServiceInterface::class);
		$segments->method('currentSegment')->willReturn($segment);
		$this->reader = $this->createMock(originalClassName: FirstTodayReader::class);
		$this->reader->method('contextFor')->willReturnCallback(
			static fn (IUser $user): TodayContext => new TodayContext(
				user: $user,
				now: new DateTimeImmutable('2026-10-05T' . $time . ':00', new DateTimeZone('Europe/Amsterdam'))
			)
		);

		return new FirstTodayService(roles: $roles, segments: $segments, reader: $this->reader, logger: new NullLogger());
	}//end service()

	/**
	 * A user signed in as daan.
	 *
	 * @return IUser
	 */
	private function daan(): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('daan');
		return $user;
	}//end daan()

	/**
	 * Meester Daan at 8.40 reads that the register of groep 7 is not filled, with the two parent reports.
	 *
	 * @return void
	 */
	public function testMeesterDaanReadsTheOpenRegisterFirst(): void {
		$service = $this->service(role: 'instructor', segment: 'po');
		$this->reader->expects(self::once())->method('groupsToday')
			->with(self::callback(callback: static fn (TodayContext $context): bool => $context->today() === '2026-10-05'), true)
			->willReturn(
				[[
					'cohort' => ['id' => 'groep-7', 'name' => 'Groep 7'],
					'sessions' => [['id' => 's-1', 'startsAt' => '2026-10-05T08:30:00+02:00']],
					'records' => ['s-1' => 0],
					'reports' => 2,
				],
				]
			);
		$this->reader->expects(self::never())->method('rows');

		$card = $service->forUser(user: $this->daan());

		self::assertSame(expected: 'group-teacher', actual: $card['role']);
		self::assertSame(expected: 'register-not-filled', actual: $card['rule']);
		self::assertSame(expected: 1, actual: $card['count']);
		self::assertSame(expected: 2, actual: $card['reportCount']);
		self::assertSame(expected: 'Groep 7', actual: $card['cohortName']);
	}//end testMeesterDaanReadsTheOpenRegisterFirst()

	/**
	 * Nothing waits: no card, and every rule of the role was asked.
	 *
	 * @return void
	 */
	public function testNothingFirstAnswersNoRule(): void {
		$service = $this->service(role: 'instructor', segment: 'vo', time: '10:30');
		$this->reader->expects(self::once())->method('groupsToday')->willReturn([]);
		$this->reader->expects(self::exactly(count: 2))->method('rows')->willReturn([]);

		self::assertSame(expected: ['role' => 'subject-teacher', 'rule' => null, 'count' => 0], actual: $service->forUser(user: $this->daan()));
	}//end testNothingFirstAnswersNoRule()

	/**
	 * In the Mentor view a teacher reads the mentor's rules, on her own mentor pupils.
	 *
	 * @return void
	 */
	public function testTheMentorViewReadsTheMentorsRules(): void {
		$service = $this->service(role: 'instructor', segment: 'vo');
		$this->reader->expects(self::once())->method('rows')
			->with('attendance-flag', ['mentorId' => 'daan', 'lifecycle' => 'open'])
			->willReturn([['learnerId' => 'yusuf', 'mentorId' => 'daan', 'lifecycle' => 'open', 'windowEnd' => '2026-10-02']]);

		$card = $service->forUser(user: $this->daan(), view: 'mentor');

		self::assertSame(expected: 'mentor', actual: $card['role']);
		self::assertSame(expected: 'unauthorised-absence', actual: $card['rule']);
		self::assertSame(expected: 'yusuf', actual: $card['learnerId']);
	}//end testTheMentorViewReadsTheMentorsRules()

	/**
	 * A role Today has no card for reads nothing.
	 *
	 * @return void
	 */
	public function testARoleWithoutACardReadsNothing(): void {
		$service = $this->service(role: 'learner', segment: 'vo');
		$this->reader->expects(self::never())->method('rows');
		$this->reader->expects(self::never())->method('groupsToday');

		self::assertSame(expected: ['role' => null, 'rule' => null, 'count' => 0], actual: $service->forUser(user: $this->daan()));
	}//end testARoleWithoutACardReadsNothing()

	/**
	 * The directeur's leave requests past half their time win over the registers.
	 *
	 * @return void
	 */
	public function testTheHeadReadsLeaveRequestsFirst(): void {
		$service = $this->service(role: 'administration-manager', segment: 'po', time: '09:05');
		$this->reader->expects(self::once())->method('rows')
			->with('leave-request', ['lifecycle' => 'submitted'])
			->willReturn(
				[
					['lifecycle' => 'submitted', 'created' => '2026-09-21T10:00:00+02:00', 'decideBy' => '2026-10-07T10:00:00+02:00'],
					['lifecycle' => 'submitted', 'created' => '2026-09-22T10:00:00+02:00', 'decideBy' => '2026-10-08T10:00:00+02:00'],
				]
			);
		$this->reader->expects(self::never())->method('groupsToday');

		$card = $service->forUser(user: $this->daan());

		self::assertSame(expected: 'leave-past-half', actual: $card['rule']);
		self::assertSame(expected: 2, actual: $card['count']);
	}//end testTheHeadReadsLeaveRequestsFirst()

	/**
	 * A failed read gives no card, so the endpoint can say it could not check.
	 *
	 * @return void
	 */
	public function testAFailedReadGivesNoCard(): void {
		$service = $this->service(role: 'coordinator', segment: 'po');
		$this->reader->method('rows')->willThrowException(new RuntimeException('database gone'));

		self::assertNull(actual: $service->cardFor(user: $this->daan()));
	}//end testAFailedReadGivesNoCard()

	/**
	 * The card comes back as forUser() answers it when the reads succeed.
	 *
	 * @return void
	 */
	public function testCardForAnswersTheCard(): void {
		$service = $this->service(role: 'coordinator', segment: 'po');
		$this->reader->method('rows')->willReturn([]);

		self::assertSame(expected: ['role' => 'care-coordinator', 'rule' => null, 'count' => 0], actual: $service->cardFor(user: $this->daan()));
	}//end testCardForAnswersTheCard()

	/**
	 * In the BPV view a teacher reads the BPV-begeleider's rules: a student without a company first.
	 *
	 * @return void
	 */
	public function testTheBpvViewReadsTheCoachsRules(): void {
		$service = $this->service(role: 'instructor', segment: 'mbo');
		$this->reader->expects(self::once())->method('rows')
			->with('bpv-placement', ['schoolCoachId' => 'daan', 'phase' => 'searching'])
			->willReturn([['learnerRef' => 'daan-smits', 'schoolCoachId' => 'daan', 'phase' => 'searching', 'periodFrom' => '2026-09-01']]);

		$card = $service->forUser(user: $this->daan(), view: 'bpv');

		self::assertSame(expected: 'bpv-coach', actual: $card['role']);
		self::assertSame(expected: 'placement-without-company', actual: $card['rule']);
		self::assertSame(expected: 'daan-smits', actual: $card['learnerRef']);
	}//end testTheBpvViewReadsTheCoachsRules()

	/**
	 * The exam committee reads results waiting when no objection is past half its term.
	 *
	 * @return void
	 */
	public function testTheExamCommitteeReadsResultsWaitingAfterObjections(): void {
		$service = $this->service(role: 'compliance-officer', segment: 'mbo');
		$this->reader->method('rows')->willReturnCallback(
			static fn (string $schema): array => match ($schema) {
				'exam-objection' => [],
				'grade-entry' => [['examSittingId' => 's-1', 'lifecycle' => 'published', 'gradedAt' => '2026-09-14T12:00:00+02:00']],
				default => [],
			}
		);

		$card = $service->forUser(user: $this->daan());

		self::assertSame(expected: 'exam-committee', actual: $card['role']);
		self::assertSame(expected: 'results-waiting', actual: $card['rule']);
		self::assertSame(expected: 1, actual: $card['sittings']);
	}//end testTheExamCommitteeReadsResultsWaitingAfterObjections()

	/**
	 * The coach's hours and visits are read only when no student is without a company.
	 *
	 * @return void
	 */
	public function testTheCoachReadsVisitsAndHoursInOrder(): void {
		$service = $this->service(role: 'instructor', segment: 'mbo');
		$asked = [];
		$this->reader->method('rows')->willReturnCallback(
			static function (string $schema, array $filters=[]) use (&$asked): array {
				$asked[] = $schema;
				return [];
			}
		);

		self::assertSame(
			expected: ['role' => 'bpv-coach', 'rule' => null, 'count' => 0],
			actual: $service->forUser(user: $this->daan(), view: 'bpv')
		);
		self::assertSame(expected: ['bpv-placement', 'bpv-visit', 'bpv-placement', 'bpv-hour-week'], actual: $asked);
	}//end testTheCoachReadsVisitsAndHoursInOrder()

	/**
	 * The exam committee reads the results to establish per sitting and the agenda; nobody else reads anything.
	 *
	 * @return void
	 */
	public function testExamCommitteeForReadsTheCommitteesListsOnly(): void {
		$service = $this->service(role: 'compliance-officer', segment: 'mbo');
		$this->reader->method('rows')->willReturnCallback(
			static fn (string $schema): array => match ($schema) {
				'grade-entry' => [
					['examSittingId' => 's-1', 'lifecycle' => 'published', 'establishedAt' => ''],
					['examSittingId' => 's-1', 'lifecycle' => 'published', 'establishedAt' => '2026-10-01T10:00:00+02:00'],
				],
				'exam-sitting' => [['id' => 's-1', 'assessmentId' => 'e-1', 'startsAt' => '2026-09-14T09:00:00+02:00']],
				'exam' => [['id' => 'e-1', 'title' => 'Engels spreken']],
				'exam-objection' => [['lifecycle' => 'examiner-responded']],
				default => [],
			}
		);

		self::assertSame(
			expected: [
				'sittings' => [['sittingId' => 's-1', 'title' => 'Engels spreken', 'startsAt' => '2026-09-14T09:00:00+02:00', 'results' => 1]],
				'agenda' => [['key' => 'objections', 'count' => 1], ['key' => 'results', 'count' => 1]],
			],
			actual: $service->examCommitteeFor(user: $this->daan())
		);

		$teacher = $this->service(role: 'instructor', segment: 'mbo');
		$this->reader->expects(self::never())->method('rows');
		self::assertSame(expected: ['sittings' => [], 'agenda' => []], actual: $teacher->examCommitteeFor(user: $this->daan()));
	}//end testExamCommitteeForReadsTheCommitteesListsOnly()

	/**
	 * A failed read of the committee's lists answers null.
	 *
	 * @return void
	 */
	public function testExamCommitteeForAnswersNullWhenTheReadFailed(): void {
		$service = $this->service(role: 'compliance-officer', segment: 'vo');
		$this->reader->method('rows')->willThrowException(new RuntimeException('down'));

		self::assertNull(actual: $service->examCommitteeFor(user: $this->daan()));
	}//end testExamCommitteeForAnswersNullWhenTheReadFailed()
}//end class
