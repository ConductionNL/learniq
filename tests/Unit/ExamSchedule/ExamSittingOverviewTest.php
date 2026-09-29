<?php

/**
 * Learniq exam schedule unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\ExamSchedule
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\ExamSchedule;
use OCA\Learniq\Controller\ExamScheduleController;
use OCA\Learniq\Service\ExamSittingOverview;
use OCA\Learniq\Tests\Support\ExamScheduleFixture;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Accommodations and invigilator places on a sitting, and who may read them.
 *
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-accommodations-in-the-schedule
 */
class ExamSittingOverviewTest extends TestCase {

	private ExamScheduleFixture $fx;

	/**
	 * A 60 minute sitting for class 5A (lrn-1, lrn-2, lrn-3), two invigilators needed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$t = ExamScheduleFixture::TENANT;
		$this->fx = new ExamScheduleFixture();
		$this->fx->add('exam-sitting', ['id' => 'sit-1', 'examPeriodId' => 'per-1', 'assessmentId' => 'asm-1', 'cohortIds' => ['coh-5a'], 'startsAt' => '2026-10-06T09:00:00+00:00', 'endsAt' => '2026-10-06T10:00:00+00:00', 'roomIds' => ['room-a1'], 'headcount' => 3, 'invigilatorsNeeded' => 2, 'lifecycle' => 'planned', 'tenant_id' => $t]);
		$this->fx->add('cohort', ['id' => 'coh-5a', 'name' => '5A', 'learnerIds' => ['lrn-1', 'lrn-2', 'lrn-3'], 'tenant_id' => $t]);
		$this->fx->add('exam-accommodation', ['id' => 'acc-1', 'learnerId' => 'lrn-1', 'accommodationKind' => 'extra-time-percentage', 'value' => 25, 'assessmentId' => null, 'lifecycle' => 'approved', 'tenant_id' => $t]);
		$this->fx->add('exam-accommodation', ['id' => 'acc-2', 'learnerId' => 'lrn-2', 'accommodationKind' => 'extra-time-percentage', 'value' => 50, 'assessmentId' => null, 'lifecycle' => 'requested', 'tenant_id' => $t]);
		$this->fx->add('exam-accommodation', ['id' => 'acc-3', 'learnerId' => 'lrn-3', 'accommodationKind' => 'separate-room', 'value' => null, 'assessmentId' => 'asm-1', 'lifecycle' => 'active', 'tenant_id' => $t]);
		$this->fx->add('exam-accommodation', ['id' => 'acc-4', 'learnerId' => 'lrn-9', 'accommodationKind' => 'extra-time-percentage', 'value' => 25, 'assessmentId' => null, 'lifecycle' => 'approved', 'tenant_id' => $t]);
		$this->fx->add('exam-accommodation', ['id' => 'acc-5', 'learnerId' => 'lrn-3', 'accommodationKind' => 'extra-time-percentage', 'value' => 25, 'assessmentId' => 'asm-other', 'lifecycle' => 'approved', 'tenant_id' => $t]);
		$this->fx->add('invigilator-assignment', ['id' => 'as-1', 'examSittingId' => 'sit-1', 'invigilatorId' => 'inv-a', 'lifecycle' => 'confirmed', 'tenant_id' => $t]);
		$this->fx->add('invigilator-assignment', ['id' => 'as-2', 'examSittingId' => 'sit-1', 'invigilatorId' => 'inv-b', 'lifecycle' => 'declined', 'tenant_id' => $t]);
		$this->fx->add('invigilator-availability', ['id' => 'av-a', 'invigilatorId' => 'inv-a', 'examPeriodId' => 'per-1', 'availableFrom' => '2026-10-06T08:00:00+00:00', 'availableUntil' => '2026-10-06T12:00:00+00:00', 'tenant_id' => $t]);
		$this->fx->add('invigilator-availability', ['id' => 'av-b', 'invigilatorId' => 'inv-b', 'examPeriodId' => 'per-1', 'availableFrom' => '2026-10-06T08:00:00+00:00', 'availableUntil' => '2026-10-06T12:00:00+00:00', 'tenant_id' => $t]);
		$this->fx->add('invigilator-availability', ['id' => 'av-c', 'invigilatorId' => 'inv-c', 'examPeriodId' => 'per-1', 'availableFrom' => '2026-10-06T09:30:00+00:00', 'availableUntil' => '2026-10-06T12:00:00+00:00', 'tenant_id' => $t]);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ExamSittingOverview
	 */
	private function overview(): ExamSittingOverview {
		return new ExamSittingOverview(objectService: $this->fx->wire($this->createMock(ObjectService::class)));
	}//end overview()

	/**
	 * 25 percent extra time on a 60 minute exam ends at 75 minutes, and the learner is listed.
	 * A requested accommodation, one for another exam and one for a learner outside the class are left out.
	 *
	 * @return void
	 */
	public function testApprovedAccommodationsApplyAndOthersDoNot(): void {
		$rows = $this->overview()->overview(sittingId: 'sit-1')['accommodations'];
		$byLearner = array_column($rows, null, 'learnerId');

		self::assertSame(['lrn-1', 'lrn-3'], array_keys($byLearner));
		self::assertSame('2026-10-06T10:15:00+00:00', $byLearner['lrn-1']['endsAt']);
		self::assertSame(25, $byLearner['lrn-1']['extraTimePercent']);
		self::assertFalse($byLearner['lrn-1']['separateRoom']);
		self::assertTrue($byLearner['lrn-3']['separateRoom']);
		self::assertSame('2026-10-06T10:00:00+00:00', $byLearner['lrn-3']['endsAt']);
	}//end testApprovedAccommodationsApplyAndOthersDoNot()

	/**
	 * One confirmed and one declined of two needed leaves one open place.
	 *
	 * @return void
	 */
	public function testADeclineLeavesAnOpenPlace(): void {
		$inv = $this->overview()->overview(sittingId: 'sit-1')['invigilators'];

		self::assertSame(2, $inv['needed']);
		self::assertSame(['inv-a'], $inv['confirmed']);
		self::assertSame([], $inv['pending']);
		self::assertSame(1, $inv['open']);
	}//end testADeclineLeavesAnOpenPlace()

	/**
	 * Only people available for the whole sitting and not already booked are offered; a decline frees the person.
	 *
	 * @return void
	 */
	public function testOnlyAvailableUnbookedPeopleAreOffered(): void {
		self::assertSame(['inv-b'], $this->overview()->availableInvigilators(sittingId: 'sit-1'));
	}//end testOnlyAvailableUnbookedPeopleAreOffered()

	/**
	 * A sitting the caller cannot read answers null.
	 *
	 * @return void
	 */
	public function testAnUnknownSittingIsNull(): void {
		self::assertNull($this->overview()->overview(sittingId: 'sit-nope'));
	}//end testAnUnknownSittingIsNull()

	/**
	 * The controller for a caller in the given groups.
	 *
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return ExamScheduleController
	 */
	private function controller(array $groups): ExamScheduleController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(in_array('admin', $groups, true));
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => in_array($group, $groups, true));

		return new ExamScheduleController(
			request: $this->createMock(IRequest::class),
			overview: $this->overview(),
			userSession: $session,
			groupManager: $groupManager,
		);
	}//end controller()

	/**
	 * A learner gets 403 on both endpoints; a team lead gets the data.
	 *
	 * @return void
	 */
	public function testOnlyPlannersReadTheOverview(): void {
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(['learners'])->overview(id: 'sit-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller([])->availableInvigilators(id: 'sit-1')->getStatus());

		$ok = $this->controller(['team-leads'])->overview(id: 'sit-1');
		self::assertSame(Http::STATUS_OK, $ok->getStatus());
		self::assertSame(1, $ok->getData()['invigilators']['open']);
		self::assertSame(['inv-b'], $this->controller(['instructors'])->availableInvigilators(id: 'sit-1')->getData()['invigilators']);
	}//end testOnlyPlannersReadTheOverview()

	/**
	 * A sitting that is not there is 404.
	 *
	 * @return void
	 */
	public function testAMissingSittingIs404(): void {
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(['admin'])->overview(id: 'sit-nope')->getStatus());
	}//end testAMissingSittingIs404()
}//end class
