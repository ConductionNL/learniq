<?php

/**
 * Learniq PeerReviewController unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Controller\PeerReviewController;
use OCA\Learniq\Service\PeerReviewAllocationService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PeerReviewController::allocate().
 */
class PeerReviewControllerTest extends TestCase {

	private const ASSIGNMENT_ID = 'assignment-1';

	/**
	 * Build a controller with the given fixtures + admin flag + delegated allocate() result.
	 *
	 * @param array<string,mixed>|null $assignment Assignment fixture, or null (not found).
	 * @param array<string,mixed>|null $cohort Cohort fixture, or null (not found).
	 * @param array<string,mixed>|null $session Session fixture, or null (not found).
	 * @param bool $isAdmin Whether the caller is a Nextcloud admin.
	 * @param string $uid The caller's uid.
	 *
	 * @return PeerReviewController
	 */
	private function makeController(
		?array $assignment,
		?array $cohort,
		?array $session,
		bool $isAdmin,
		string $uid = 'teacher-1',
	): PeerReviewController {
		$objectService = $this->createMock(ObjectService::class);
		// OpenRegister's find() is find($id, $_extend, $files, $register, $schema, ...)
		// and returns ?ObjectEntity. willReturnCallback() hands the closure the
		// mock's arguments POSITIONALLY, so the closure must mirror that order.
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($assignment, $cohort, $session) {
				if ($schema === 'assignment' && $assignment !== null) {
					return OrEntityFactory::make($assignment, 'assignment');
				}

				if ($schema === 'cohort' && $cohort !== null) {
					return OrEntityFactory::make($cohort, 'cohort');
				}

				if ($schema === 'session' && $session !== null) {
					return OrEntityFactory::make($session, 'session');
				}

				return null;
			}
		);

		$allocationService = $this->createMock(PeerReviewAllocationService::class);
		$allocationService->method('allocate')->willReturn(
			['strategy' => 'round-robin', 'submissionsProcessed' => 1, 'createdCount' => 2]
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		return new PeerReviewController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			groupManager: $groupManager,
			objectService: $objectService,
			allocationService: $allocationService,
		);
	}//end makeController()

	/**
	 * Decode a JSONResponse body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array<string,mixed>
	 */
	private function body(JSONResponse $response): array {
		return (array)$response->getData();
	}//end body()

	/**
	 * An admin caller is always authorized, regardless of Cohort membership.
	 *
	 * @return void
	 */
	public function testAdminIsAuthorized(): void {
		$controller = $this->makeController(
			assignment: ['id' => self::ASSIGNMENT_ID, 'cohortId' => 'cohort-1'],
			cohort: ['id' => 'cohort-1', 'teacherIds' => []],
			session: null,
			isAdmin: true,
		);

		$response = $controller->allocate(self::ASSIGNMENT_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(2, $this->body($response)['result']['createdCount']);
	}//end testAdminIsAuthorized()

	/**
	 * A teacher listed on the Assignment's own Cohort (direct cohortId) is authorized.
	 *
	 * @return void
	 */
	public function testCohortTeacherIsAuthorizedViaDirectCohortId(): void {
		$controller = $this->makeController(
			assignment: ['id' => self::ASSIGNMENT_ID, 'cohortId' => 'cohort-1'],
			cohort: ['id' => 'cohort-1', 'teacherIds' => ['teacher-1']],
			session: null,
			isAdmin: false,
			uid: 'teacher-1',
		);

		$response = $controller->allocate(self::ASSIGNMENT_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testCohortTeacherIsAuthorizedViaDirectCohortId()

	/**
	 * A teacher listed on the Cohort resolved via Assignment.sessionId -> Session.cohortId
	 * is authorized (Assignment has no direct cohortId).
	 *
	 * @return void
	 */
	public function testCohortTeacherIsAuthorizedViaSession(): void {
		$controller = $this->makeController(
			assignment: ['id' => self::ASSIGNMENT_ID, 'sessionId' => 'session-1'],
			cohort: ['id' => 'cohort-1', 'teacherIds' => ['teacher-1']],
			session: ['id' => 'session-1', 'cohortId' => 'cohort-1'],
			isAdmin: false,
			uid: 'teacher-1',
		);

		$response = $controller->allocate(self::ASSIGNMENT_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testCohortTeacherIsAuthorizedViaSession()

	/**
	 * A caller who is neither admin nor a listed Cohort teacher receives a 403.
	 *
	 * @return void
	 */
	public function testUnauthorizedCallerReceives403(): void {
		$controller = $this->makeController(
			assignment: ['id' => self::ASSIGNMENT_ID, 'cohortId' => 'cohort-1'],
			cohort: ['id' => 'cohort-1', 'teacherIds' => ['teacher-1']],
			session: null,
			isAdmin: false,
			uid: 'random-user',
		);

		$response = $controller->allocate(self::ASSIGNMENT_ID);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testUnauthorizedCallerReceives403()

	/**
	 * A caller with no resolvable Cohort at all (no cohortId, no sessionId) is denied.
	 *
	 * @return void
	 */
	public function testNoResolvableCohortDenies(): void {
		$controller = $this->makeController(
			assignment: ['id' => self::ASSIGNMENT_ID],
			cohort: null,
			session: null,
			isAdmin: false,
			uid: 'teacher-1',
		);

		$response = $controller->allocate(self::ASSIGNMENT_ID);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testNoResolvableCohortDenies()

	/**
	 * A missing Assignment returns 404.
	 *
	 * @return void
	 */
	public function testMissingAssignmentReturns404(): void {
		$controller = $this->makeController(assignment: null, cohort: null, session: null, isAdmin: true);

		$response = $controller->allocate(self::ASSIGNMENT_ID);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testMissingAssignmentReturns404()

	/**
	 * A missing assignmentId parameter returns 400.
	 *
	 * @return void
	 */
	public function testMissingAssignmentIdReturns400(): void {
		$controller = $this->makeController(assignment: null, cohort: null, session: null, isAdmin: true);

		$response = $controller->allocate('');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testMissingAssignmentIdReturns400()

	/**
	 * An unknown assignment id returns 404, not a 500.
	 *
	 * OpenRegister's ObjectService::find() THROWS DoesNotExistException for an
	 * unknown id rather than returning null, so before the catch in
	 * fetchObject() this path escaped the controller entirely and surfaced as a
	 * 500 with a stack trace. The `$assignment === null` branch that was
	 * supposed to produce the 404 could never be reached.
	 *
	 * Asserting on the STATUS CODE, not merely that no exception escaped: a
	 * test that only wrapped the call in a try/catch would still pass if the
	 * controller returned a 500 body.
	 *
	 * @return void
	 */
	public function testUnknownAssignmentThrowingFromObjectServiceReturns404(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException('no such object')
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('teacher-1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$controller = new PeerReviewController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			groupManager: $groupManager,
			objectService: $objectService,
			allocationService: $this->createMock(PeerReviewAllocationService::class),
		);

		$response = $controller->allocate(self::ASSIGNMENT_ID);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Assignment not found', $this->body($response)['error'] ?? null);
	}//end testUnknownAssignmentThrowingFromObjectServiceReturns404()
}//end class
