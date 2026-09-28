<?php

/**
 * Learniq Submission Mark Controller
 *
 * Double marking (assignments-double-marking): the teacher in charge
 * allocates markers to an assignment's handed-in submissions, and a marker
 * reads the marks of one submission. Both methods are `#[NoAdminRequired]`
 * with the check in the body: allocation needs a user in `instructors`,
 * `compliance-officers` or `team-leads` (or an admin); reading marks needs an
 * allocated marker or a user who reads every mark (compliance officers, team
 * leads, admins), and SubmissionMarkReader decides which marks come back.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/changes/assignments-double-marking/specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\SubmissionMarkAllocationService;
use OCA\Learniq\Service\SubmissionMarkReader;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Allocation of markers and the marks read of one submission.
 *
 * @spec openspec/changes/assignments-double-marking/specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers
 */
class SubmissionMarkController extends Controller {

	private const REGISTER = 'learniq';

	/**
	 * Groups that may allocate markers.
	 */
	private const ALLOCATOR_GROUPS = ['instructors', 'compliance-officers', 'team-leads'];

	/**
	 * Groups that read every mark of a submission at any time.
	 */
	private const SEES_ALL_GROUPS = ['compliance-officers', 'team-leads'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                        $request      The request.
	 * @param IUserSession                    $userSession  The signed-in user.
	 * @param IGroupManager                   $groupManager Group membership and admin checks.
	 * @param ObjectService                   $objects      OpenRegister object access.
	 * @param SubmissionMarkAllocationService $allocation   The allocation rules.
	 * @param SubmissionMarkReader            $reader       Which marks a caller may read.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ObjectService $objects,
		private readonly SubmissionMarkAllocationService $allocation,
		private readonly SubmissionMarkReader $reader,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Allocate markers to every handed-in submission of an assignment, or to
	 * the one named by `submissionId`. Body: `markerIds` (list of user ids),
	 * optional `submissionId`.
	 *
	 * @param string $assignmentId The assignment's uuid.
	 *
	 * @return JSONResponse 200 with the summary, or 401 / 403 / 404 / 422.
	 *
	 * @spec openspec/changes/assignments-double-marking/specs/assignments/spec.md#scenario-a-coordinator-allocates-two-markers-to-every-hand-in
	 */
	#[NoAdminRequired]
	public function allocate(string $assignmentId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->inAnyGroup(userId: $user->getUID(), groups: self::ALLOCATOR_GROUPS) === false) {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$assignment = $this->read(schema: 'assignment', id: $assignmentId);
		if ($assignment === null) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$markerIds = $this->request->getParam('markerIds', []);
		if (is_array($markerIds) === false) {
			$markerIds = [];
		}

		$submissionId = $this->request->getParam('submissionId', '');
		if (is_string($submissionId) === false) {
			$submissionId = '';
		}

		$result = $this->allocation->allocate(assignment: $assignment, markerIds: $markerIds, submissionId: trim($submissionId));

		if ($result['error'] === 'submission-not-found') {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($result['error'] !== null) {
			return new JSONResponse(data: ['error' => $result['error']], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse(data: $result);
	}//end allocate()

	/**
	 * The marks of one submission the caller may read. A marker whose own
	 * mark is a draft gets only that draft; everyone else who may read gets
	 * every mark and the summary. Anyone else gets 404, the same as a missing
	 * submission.
	 *
	 * @param string $submissionId The submission's uuid.
	 *
	 * @return JSONResponse 200 `{marks, ownMark, complete, summary, finalGradeRule}`, or 401 / 404.
	 *
	 * @spec openspec/changes/assignments-double-marking/specs/assignments/spec.md#scenario-the-second-marker-cannot-peek
	 */
	#[NoAdminRequired]
	public function marks(string $submissionId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$submission = $this->read(schema: 'submission', id: $submissionId);
		if ($submission === null) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$seesAll = $this->inAnyGroup(userId: $user->getUID(), groups: self::SEES_ALL_GROUPS);
		$view = $this->reader->forCaller(submission: $submission, userId: $user->getUID(), seesAll: $seesAll);
		if ($view['allowed'] === false) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$assignment = $this->read(schema: 'assignment', id: (string)($submission['assignmentId'] ?? ''));

		return new JSONResponse(
			data: [
				'marks' => $view['marks'],
				'ownMark' => $view['ownMark'],
				'complete' => $view['complete'],
				'summary' => $view['summary'],
				'finalGradeRule' => (string)($assignment['finalGradeRule'] ?? 'manual'),
			]
		);
	}//end marks()

	/**
	 * Whether the user is an admin or in one of the groups.
	 *
	 * @param string        $userId The user id.
	 * @param array<string> $groups The group ids.
	 *
	 * @return bool
	 */
	private function inAnyGroup(string $userId, array $groups): bool {
		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		foreach ($groups as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return true;
			}
		}

		return false;
	}//end inAnyGroup()

	/**
	 * One learniq object by uuid, read as the system after the caller's
	 * check, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objects->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _render: false);
		} catch (DoesNotExistException) {
			return null;
		}

		$row = $object?->jsonSerialize();
		if (is_array($row) === false) {
			return null;
		}

		$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? $id));

		return $row;
	}//end read()
}//end class
