<?php

/**
 * Learniq CourseEvaluationEligibilityGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\CourseEvaluationEligibilityGuard;
use OCP\IUser;
use OCP\IUserSession;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the CourseEvaluationEligibilityGuard lifecycle guard (draft → submitted).
 */
class CourseEvaluationEligibilityGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * ObjectService mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * User-session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);

	}//end setUp()

	/**
	 * Make IUserSession return a user with the given uid.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end signInAs()

	/**
	 * Wire ObjectService::findAll to return the given EvaluationInvitation row(s).
	 *
	 * @param array<int, array<string, mixed>> $invitations Rows to return for an evaluation-invitation query.
	 *
	 * @return void
	 */
	private function wireInvitations(array $invitations): void {
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($invitations) {
				if ($config['filters']['schema'] === 'evaluation-invitation') {
					return $invitations;
				}

				return [];
			}
		);

	}//end wireInvitations()

	/**
	 * Build the guard under test.
	 *
	 * @return CourseEvaluationEligibilityGuard
	 */
	private function makeGuard(): CourseEvaluationEligibilityGuard {
		return new CourseEvaluationEligibilityGuard(
			$this->userSession,
			$this->objectService,
			$this->createMock(LoggerInterface::class),
		);

	}//end makeGuard()

	/**
	 * A learner with no EvaluationInvitation for the campaign is blocked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#scenario-a-learner-without-an-invitation-cannot-submit
	 */
	public function testNoInvitationBlocksSubmit(): void {
		$this->signInAs('learner-1');
		$this->wireInvitations([]);

		$object = ['campaignId' => 'campaign-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'submitted'];

		self::assertDenied($this->makeGuard()->check($object, 'submit', ''));

	}//end testNoInvitationBlocksSubmit()

	/**
	 * A learner whose EvaluationInvitation for the campaign already has
	 * hasResponded:true is blocked from a second submission (the filter
	 * itself excludes it, mirroring the guard's own findAll filter).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#scenario-a-learner-cannot-submit-a-second-response-for-the-same-campaign
	 */
	public function testAlreadyRespondedBlocksSecondSubmit(): void {
		$this->signInAs('learner-1');
		// The guard filters hasResponded:false server-side — an already-responded
		// invitation never matches, so findAll returns empty for this caller.
		$this->wireInvitations([]);

		$object = ['campaignId' => 'campaign-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'submitted'];

		self::assertDenied($this->makeGuard()->check($object, 'submit', ''));

	}//end testAlreadyRespondedBlocksSecondSubmit()

	/**
	 * An eligible, not-yet-responded invitation allows the submit.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
	 */
	public function testEligibleInvitationAllowsSubmit(): void {
		$this->signInAs('learner-1');
		$this->wireInvitations(
			[
				[
					'campaignId' => 'campaign-1',
					'learnerId' => 'learner-1',
					'hasResponded' => false,
				],
			]
		);

		$object = ['campaignId' => 'campaign-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'submitted'];

		self::assertAllowed($this->makeGuard()->check($object, 'submit', ''));

	}//end testEligibleInvitationAllowsSubmit()

	/**
	 * The guard never reads or mutates a learner-identity field on the
	 * CourseEvaluationResponse payload it receives — the payload it is
	 * given carries none, and the guard's own filters key off the
	 * *session-resolved* caller, never the object payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#requirement-a-response-is-anonymous-by-schema-shape-not-by-rbac
	 */
	public function testGuardNeverMutatesResponsePayload(): void {
		$this->signInAs('learner-1');
		$this->wireInvitations(
			[
				[
					'campaignId' => 'campaign-1',
					'courseId' => 'course-1',
					'learnerId' => 'learner-1',
					'hasResponded' => false,
				],
			]
		);

		$original = [
			'campaignId' => 'campaign-1',
			'courseId' => 'course-1',
			'answers' => [],
			'tenant_id' => 'tenant-a',
		];
		$object = array_merge($original, ['lifecycle' => 'submitted']);

		self::assertAllowed($this->makeGuard()->check($object, 'submit', ''));

		// OpenRegister hands the guard the object by value, so the guard can not
		// add, remove or change a key on the response it judges (anonymity).
		$parameter = (new \ReflectionMethod(CourseEvaluationEligibilityGuard::class, 'check'))->getParameters()[0];
		self::assertFalse($parameter->isPassedByReference());
		self::assertArrayNotHasKey('learnerId', $object);
		self::assertArrayNotHasKey('submittedBy', $object);

	}//end testGuardNeverMutatesResponsePayload()

	/**
	 * No authenticated session (getUser() returns null) fails closed.
	 *
	 * @return void
	 */
	public function testNoAuthenticatedUserFailsClosed(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->wireInvitations(
			[
				[
					'campaignId' => 'campaign-1',
					'learnerId' => 'learner-1',
					'hasResponded' => false,
				],
			]
		);

		$object = ['campaignId' => 'campaign-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'submitted'];

		self::assertDenied($this->makeGuard()->check($object, 'submit', ''));

	}//end testNoAuthenticatedUserFailsClosed()

	/**
	 * A missing campaignId on the response fails closed without querying.
	 *
	 * @return void
	 */
	public function testMissingCampaignIdFailsClosedWithoutQuerying(): void {
		$this->signInAs('learner-1');
		$this->objectService->expects(self::never())->method('findAll');

		$object = ['tenant_id' => 'tenant-a', 'lifecycle' => 'submitted'];

		self::assertDenied($this->makeGuard()->check($object, 'submit', ''));

	}//end testMissingCampaignIdFailsClosedWithoutQuerying()
	/**
	 * The guard over a store that binds filters the way OpenRegister does on
	 * PostgreSQL (live pass D5): a `hasResponded => false` filter is refused
	 * there, so the guard must not send one, and an answered invitation is
	 * still not a second chance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#scenario-a-learner-without-an-invitation-cannot-submit
	 */
	public function testTheGuardWorksOnAPostgresBoundStore(): void {
		$store = new RegisterFaithfulStore();
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);
		$this->signInAs('learner-1');
		$guard = new CourseEvaluationEligibilityGuard($this->userSession, $objects, $this->createMock(LoggerInterface::class));
		$object = ['campaignId' => 'campaign-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'submitted'];

		$store->rows['evaluation-invitation'] = [
			['id' => 'inv-1', 'campaignId' => 'campaign-1', 'learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'hasResponded' => true],
		];
		self::assertDenied($guard->check($object, 'submit', ''));

		$store->rows['evaluation-invitation'][] = ['id' => 'inv-2', 'campaignId' => 'campaign-1', 'learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'hasResponded' => false];
		self::assertAllowed($guard->check($object, 'submit', ''));
	}//end testTheGuardWorksOnAPostgresBoundStore()

	/**
	 * A guard reading the shipped register through RegisterFaithfulStore, with
	 * one open invitation for learner-1 in the given shape.
	 *
	 * @param array<int, array<string, mixed>> $invitations The stored evaluation-invitation rows.
	 *
	 * @return CourseEvaluationEligibilityGuard
	 */
	private function guardOverStore(array $invitations): CourseEvaluationEligibilityGuard {
		$store = new RegisterFaithfulStore();
		$store->rows['evaluation-invitation'] = $invitations;
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);

		return new CourseEvaluationEligibilityGuard($this->userSession, $objects, $this->createMock(LoggerInterface::class));
	}//end guardOverStore()

	/**
	 * An invitation as the provisioning writes it (every required property of
	 * the shipped evaluation-invitation fragment, plus cohortId).
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function invitationRow(array $override = []): array {
		return array_merge(
			[
				'id' => '0a000000-0000-4000-8000-000000000001',
				'campaignId' => '0c000000-0000-4000-8000-000000000001',
				'courseId' => '0d000000-0000-4000-8000-00000000000a',
				'cohortId' => '0e000000-0000-4000-8000-00000000000a',
				'learnerId' => 'learner-1',
				'hasResponded' => false,
				'respondedAt' => null,
				'campaignClosesAt' => '2026-12-01T00:00:00+00:00',
				'academicYear' => '2026-2027',
				'period' => 'P1',
				'tenant_id' => '0f000000-0000-4000-8000-000000000001',
			],
			$override
		);
	}//end invitationRow()

	/**
	 * The response row the answer page builds from that invitation
	 * (CourseEvaluationResponseBuilder::responsePayload), at its target state.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function responseRow(array $override = []): array {
		return array_merge(
			[
				'campaignId' => '0c000000-0000-4000-8000-000000000001',
				'courseId' => '0d000000-0000-4000-8000-00000000000a',
				'cohortId' => '0e000000-0000-4000-8000-00000000000a',
				'academicYear' => '2026-2027',
				'period' => 'P1',
				'overallScore' => 4.0,
				'answers' => [['questionId' => 'q1', 'ratingValue' => 4]],
				'lifecycle' => 'submitted',
				'tenant_id' => '0f000000-0000-4000-8000-000000000001',
			],
			$override
		);
	}//end responseRow()

	/**
	 * The row an invited learner submits must be the one their invitation
	 * describes, not only the same campaign.
	 *
	 * The draft rule of #1715 lets any signed-in user change a left-over
	 * draft. Checking only campaignId let an invited learner point such a
	 * draft at another course, cohort or teacher of the same campaign (or
	 * another year or period, which CourseQualityScoreEvaluator scopes by)
	 * and submit it, moving their vote to something they were not invited to
	 * judge.
	 *
	 * @param array<string, mixed> $tampered The field the submitted row changes.
	 *
	 * @return void
	 *
	 * @dataProvider tamperedRows
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
	 */
	public function testARowThatDiffersFromTheInvitationIsRefused(array $tampered): void {
		$this->signInAs('learner-1');
		$guard = $this->guardOverStore([self::invitationRow()]);

		self::assertDenied($guard->check(self::responseRow($tampered), 'submit', ''));
	}//end testARowThatDiffersFromTheInvitationIsRefused()

	/**
	 * One field the submitted row may not change, each.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function tamperedRows(): array {
		return [
			'another course' => [['courseId' => '0d000000-0000-4000-8000-00000000000b']],
			'another cohort' => [['cohortId' => '0e000000-0000-4000-8000-00000000000b']],
			'no cohort' => [['cohortId' => null]],
			'a teacher the invitation does not name' => [['teacherId' => 'teacher-x']],
			'another academic year' => [['academicYear' => '2025-2026']],
			'another period' => [['period' => 'P2']],
		];
	}//end tamperedRows()

	/**
	 * The row the answer page builds from the invitation passes; so does a row
	 * matching the second of two invitations in the same campaign.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
	 */
	public function testTheRowTheInvitationDescribesIsAccepted(): void {
		$this->signInAs('learner-1');
		$guard = $this->guardOverStore(
			[
				self::invitationRow(),
				self::invitationRow(['id' => '0a000000-0000-4000-8000-000000000002', 'courseId' => '0d000000-0000-4000-8000-00000000000b', 'cohortId' => null]),
			]
		);

		self::assertAllowed($guard->check(self::responseRow(), 'submit', ''));
		self::assertAllowed($guard->check(self::responseRow(['courseId' => '0d000000-0000-4000-8000-00000000000b', 'cohortId' => null]), 'submit', ''));
		self::assertDenied($guard->check(self::responseRow(['courseId' => '0d000000-0000-4000-8000-00000000000b']), 'submit', ''));
	}//end testTheRowTheInvitationDescribesIsAccepted()

	/**
	 * A caller with no invitation is still refused, even for a row that
	 * matches someone else's invitation exactly.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#scenario-a-learner-without-an-invitation-cannot-submit
	 */
	public function testAnUninvitedCallerIsStillRefused(): void {
		$this->signInAs('learner-2');
		$guard = $this->guardOverStore([self::invitationRow()]);

		self::assertDenied($guard->check(self::responseRow(), 'submit', ''));
	}//end testAnUninvitedCallerIsStillRefused()
}//end class
