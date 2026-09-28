<?php

/**
 * Learniq Agent Tools
 *
 * The curated write tools an agent in hermiq may call on learniq (ADR-063
 * decision 2): enrol a learner, record attendance, propose a grade, and one
 * minimised read of expiring certificates. Each method is an `#[McpTool]`
 * that OpenRegister's `AttributeToolScanner` finds through
 * {@see LearniqScannableServices}; OpenRegister serves it on both MCP surfaces
 * and writes one audit record per call with the calling user and the tool id.
 *
 * Every write runs in the caller's own session and goes through
 * `ObjectService` with RBAC on, which is the path the app's own screens use,
 * so every lifecycle guard fires exactly as it does in the UI. On top of that
 * each tool asks the ADR-023 action matrix first (`mcp.*` rows, admin-only by
 * default). A grade is written as a `concept`: it reaches nobody until a
 * teacher publishes it in the gradebook, the same step a grade marked in the
 * app goes through.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-declared-scope-req-007
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * Agent-callable learniq actions, each a thin, gated wrapper over the UI's own write path.
 */
class LearniqAgentTools {

	/**
	 * The learniq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'learniq';

	/**
	 * Attendance statuses an agent may record (the AttendanceRecord enum).
	 *
	 * @var array<int, string>
	 */
	public const ATTENDANCE_STATUSES = ['present', 'absent-unexcused', 'absent-excused', 'late', 'left-early'];

	/**
	 * The only fields the expiring-credentials read returns (REQ-011, closed list).
	 *
	 * @var array<int, string>
	 */
	public const EXPIRING_FIELDS = ['credentialId', 'learnerId', 'learnerDisplayName', 'courseId', 'courseTitle', 'expiresAt', 'renewalCourseSlug'];

	/**
	 * Largest page the expiring-credentials read returns.
	 *
	 * @var int
	 */
	private const MAX_EXPIRING = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectService     $objectService OpenRegister object access, in the caller's session.
	 * @param IUserSession      $userSession   The calling user.
	 * @param ActionAuthService $actionAuth    The ADR-023 action matrix.
	 * @param AgentToolAnswer   $answer        Shapes success and error envelopes.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly AgentToolAnswer $answer,
	) {
	}//end __construct()

	// The attribute sits above the docblock so gate-16 still finds the @spec
	// tag (a multi-line attribute breaks its line walk otherwise).
	#[McpTool(
		name: 'enrolLearner',
		subject: 'enrolment',
		action: 'create',
		description: 'Enrol one learner in one course. The enrolment starts as pending, as one made in the app does. '
			. 'Calling it again for an open enrolment returns that one.',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'create'
	)]
	/**
	 * Enrol one learner in one course.
	 *
	 * @param string $learnerId The learner's Nextcloud user id.
	 * @param string $courseId  The course UUID.
	 * @param string $reason    Why, in a sentence (kept on the enrolment).
	 *
	 * @return array<string, mixed> `{ok: true, enrolmentId, created}` or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-tool-delegates-to-the-existing-guarded-path-and-cannot-bypass-a-gate-req-008
	 */
	public function enrolLearner(string $learnerId, string $courseId, string $reason = ''): array {
		$user = $this->authorise(action: 'mcp.enrol-learner');
		if ($user === null || trim($learnerId) === '' || trim($courseId) === '') {
			return $this->answer->error(code: 'invalid', message: 'Sign in, have the mcp.enrol-learner right, and give a learnerId and a courseId.');
		}

		$course = $this->read(schema: 'course', id: $courseId);
		if ($course === null) {
			return $this->answer->error(code: 'not_found', message: 'No course with that id that you can read.');
		}

		$open = $this->first(schema: 'enrolment', filters: ['learnerId' => $learnerId, 'courseId' => $courseId, 'lifecycle' => ['pending', 'active']]);
		if ($open !== null) {
			return $this->answer->success(data: ['enrolmentId' => (string)($open['id'] ?? ''), 'created' => false]);
		}

		return $this->write(
			schema: 'enrolment',
			object: [
				'learnerId' => $learnerId,
				'courseId'  => $courseId,
				'source'    => 'system',
				'reason'    => $this->agentNote(user: $user, tool: 'enrolLearner', text: $reason),
				'lifecycle' => 'pending',
				'tenant_id' => (string)($course['tenant_id'] ?? ''),
			],
			idKey: 'enrolmentId'
		);
	}//end enrolLearner()

	#[McpTool(
		name: 'recordAttendance',
		subject: 'attendance',
		action: 'create',
		description: 'Record one learner\'s attendance for one session: present, absent-unexcused, absent-excused, late or left-early. '
			. 'A second call for the same learner and session corrects the first.',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'create'
	)]
	/**
	 * Record, or correct, one learner's attendance for one session.
	 *
	 * @param string $sessionId The session UUID.
	 * @param string $learnerId The learner's Nextcloud user id.
	 * @param string $status    One of ATTENDANCE_STATUSES.
	 * @param string $reason    Optional note, e.g. why a learner was absent.
	 *
	 * @return array<string, mixed> `{ok: true, attendanceRecordId, created}` or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-tool-delegates-to-the-existing-guarded-path-and-cannot-bypass-a-gate-req-008
	 */
	public function recordAttendance(string $sessionId, string $learnerId, string $status, string $reason = ''): array {
		$user = $this->authorise(action: 'mcp.record-attendance');
		if ($user === null || in_array($status, self::ATTENDANCE_STATUSES, true) === false || trim($learnerId) === '') {
			return $this->answer->error(
				code: 'invalid',
				message: 'Sign in, have the mcp.record-attendance right, give a learnerId and a status from: ' . implode(', ', self::ATTENDANCE_STATUSES) . '.'
			);
		}

		$session = $this->read(schema: 'session', id: $sessionId);
		if ($session === null) {
			return $this->answer->error(code: 'not_found', message: 'No session with that id that you can read.');
		}

		$existing   = $this->first(schema: 'attendance-record', filters: ['sessionId' => $sessionId, 'learnerId' => $learnerId]);
		$existingId = null;
		if ($existing !== null) {
			$existingId = (string)($existing['id'] ?? '');
		}

		return $this->write(
			schema: 'attendance-record',
			object: [
				'sessionId' => $sessionId,
				'learnerId' => $learnerId,
				'cohortId'  => (string)($session['cohortId'] ?? ''),
				'status'    => $status,
				'markedBy'  => $user->getUID(),
				'markedAt'  => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
				'reason'    => $this->agentNote(user: $user, tool: 'recordAttendance', text: $reason),
				'tenant_id' => (string)($session['tenant_id'] ?? ''),
			],
			idKey: 'attendanceRecordId',
			uuid: $existingId
		);
	}//end recordAttendance()

	#[McpTool(
		name: 'gradeSubmission',
		subject: 'grade',
		action: 'propose',
		description: 'Propose a grade for one handed-in submission. The grade is saved as a concept: '
			. 'learners do not see it until a teacher publishes it in the gradebook.',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: false,
		scope: 'create'
	)]
	/**
	 * Propose a concept grade for one submission; a teacher publishes it.
	 *
	 * @param string $submissionId The submission UUID.
	 * @param float  $value        The proposed grade on the assignment's scale.
	 * @param string $comment      Optional reasoning for the teacher.
	 *
	 * @return array<string, mixed> `{ok: true, gradeEntryId, lifecycle: "concept"}` or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-an-agent-grade-is-a-concept-a-teacher-publishes-req-009
	 */
	public function gradeSubmission(string $submissionId, float $value, string $comment = ''): array {
		$user = $this->authorise(action: 'mcp.grade-submission');
		if ($user === null) {
			return $this->answer->error(code: 'forbidden', message: 'You need the mcp.grade-submission right.');
		}

		$submission = $this->read(schema: 'submission', id: $submissionId);
		$assignment = $this->read(schema: 'assignment', id: (string)($submission['assignmentId'] ?? ''));
		if ($submission === null || $assignment === null) {
			return $this->answer->error(code: 'not_found', message: 'No handed-in submission with that id that you can read.');
		}

		$learnerId = (string)(($submission['learnerIds'] ?? [])[0] ?? '');
		$planId    = (string)($assignment['curriculumPlanId'] ?? '');
		$component = (string)($assignment['curriculumPlanComponentId'] ?? '');
		if ($learnerId === '' || $planId === '' || $component === '') {
			return $this->answer->error(code: 'invalid', message: 'This assignment is not linked to a curriculum plan component, so it cannot carry a grade.');
		}

		return $this->write(
			schema: 'grade-entry',
			object: [
				'learnerId'        => $learnerId,
				'curriculumPlanId' => $planId,
				'componentId'      => $component,
				'gradeScaleId'     => (string)($assignment['gradeScaleId'] ?? ''),
				'sourceKind'       => 'assignment-submission',
				'submissionId'     => $submissionId,
				'value'            => $value,
				'grader'           => $user->getUID(),
				'gradedAt'         => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
				'comment'          => $this->agentNote(user: $user, tool: 'gradeSubmission', text: $comment),
				'lifecycle'        => 'concept',
				'tenant_id'        => (string)($submission['tenant_id'] ?? ''),
			],
			idKey: 'gradeEntryId'
		);
	}//end gradeSubmission()

	#[McpTool(
		name: 'listExpiringCredentials',
		subject: 'credential',
		action: 'list',
		description: 'List certificates that expire before a date (YYYY-MM-DD), with only: credentialId, learnerId, '
			. 'learnerDisplayName, courseId, courseTitle, expiresAt, renewalCourseSlug. Feed learnerId and the renewal course to enrolLearner.',
		readOnlyHint: true,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'read'
	)]
	/**
	 * Certificates expiring before a date, as a closed, minimised projection.
	 *
	 * Reads with the caller's rights, so a certificate they may not read is
	 * absent. Never returns signatures, payloads, wallet fields or any learner
	 * field beyond the uid and display name.
	 *
	 * @param string $before   The cut-off date (YYYY-MM-DD or ISO 8601).
	 * @param string $courseId Optional course filter.
	 *
	 * @return array<string, mixed> `{ok: true, credentials: [...]}` or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-the-expiring-credentials-read-is-a-closed-minimised-projection-req-011
	 */
	public function listExpiringCredentials(string $before, string $courseId = ''): array {
		$cutOff = strtotime($before);
		if ($this->userSession->getUser() === null || $cutOff === false) {
			return $this->answer->error(code: 'invalid', message: 'Sign in and give a date, for example 2026-12-31.');
		}

		$filters = ['lifecycle' => 'issued'];
		if ($courseId !== '') {
			$filters['courseId'] = $courseId;
		}

		$rows = [];
		foreach ($this->all(schema: 'credential', filters: $filters) as $credential) {
			$expiresAt = strtotime((string)($credential['expiresAt'] ?? ''));
			if ($expiresAt === false || $expiresAt >= $cutOff) {
				continue;
			}

			$rows[] = $this->project(credential: $credential);
		}

		return $this->answer->success(data: ['credentials' => $rows]);
	}//end listExpiringCredentials()

	/**
	 * The closed projection of one credential.
	 *
	 * @param array<string, mixed> $credential The credential.
	 *
	 * @return array<string, string> Exactly EXPIRING_FIELDS.
	 */
	private function project(array $credential): array {
		$learnerId = (string)($credential['learnerId'] ?? '');
		$course    = $this->read(schema: 'course', id: (string)($credential['courseId'] ?? '')) ?? [];

		return [
			'credentialId'       => (string)($credential['id'] ?? ''),
			'learnerId'          => $learnerId,
			'learnerDisplayName' => $this->displayName(uid: $learnerId),
			'courseId'           => (string)($credential['courseId'] ?? ''),
			'courseTitle'        => (string)($course['title'] ?? ''),
			'expiresAt'          => (string)($credential['expiresAt'] ?? ''),
			'renewalCourseSlug'  => (string)($course['renewalCourseSlug'] ?? ''),
		];
	}//end project()

	/**
	 * The signed-in user when the action matrix allows the action, else null.
	 *
	 * @param string $action The ADR-023 action.
	 *
	 * @return IUser|null The user.
	 */
	private function authorise(string $action): ?IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: $action);
		} catch (Throwable $e) {
			return null;
		}

		return $user;
	}//end authorise()

	/**
	 * Save through the caller's session (RBAC and lifecycle guards on) and answer.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The payload.
	 * @param string               $idKey  The key the new id is returned under.
	 * @param string|null          $uuid   An existing object to update, if any.
	 *
	 * @return array<string, mixed> The answer envelope.
	 */
	private function write(string $schema, array $object, string $idKey, ?string $uuid = null): array {
		if ($uuid === '') {
			$uuid = null;
		}

		try {
			$saved = $this->objectService->saveObject(object: $object, register: self::REGISTER, schema: $schema, uuid: $uuid);
		} catch (Throwable $e) {
			// A guard or RBAC refusal: the same refusal the UI gets, passed on as is.
			return $this->answer->error(code: 'refused', message: $e->getMessage());
		}

		$data = $saved->jsonSerialize();

		return $this->answer->success(
			data: [
				$idKey      => (string)($data['id'] ?? ($data['@self']['id'] ?? '')),
				'created'   => ($uuid === null),
				'lifecycle' => (string)($data['lifecycle'] ?? ''),
			]
		);
	}//end write()

	/**
	 * Read one object with the caller's rights, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The UUID.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema);
		} catch (Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end read()

	/**
	 * The first object matching filters, read with the caller's rights, or null.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	private function first(string $schema, array $filters): ?array {
		return ($this->all(schema: $schema, filters: $filters, limit: 1)[0] ?? null);
	}//end first()

	/**
	 * Objects matching filters, read with the caller's rights.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 * @param int                  $limit   Page size.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function all(string $schema, array $filters, int $limit = self::MAX_EXPIRING): array {
		try {
			$rows = $this->objectService->findAll(
				config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => $limit]
			);
		} catch (Throwable $e) {
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				$row = (array)$row->jsonSerialize();
			}

			$result[] = $row;
		}

		return $result;
	}//end all()

	/**
	 * A user's display name, or the uid when unknown.
	 *
	 * @param string $uid The uid.
	 *
	 * @return string The name.
	 */
	private function displayName(string $uid): string {
		return $this->answer->displayName(uid: $uid);
	}//end displayName()

	/**
	 * The note every agent write carries, so the record says a tool made it.
	 *
	 * @param IUser  $user The calling user.
	 * @param string $tool The tool name.
	 * @param string $text The caller's own text.
	 *
	 * @return string The note.
	 */
	private function agentNote(IUser $user, string $tool, string $text): string {
		$note = 'Written by the agent tool learniq.' . $tool . ' for ' . $user->getUID() . '.';
		if (trim($text) === '') {
			return $note;
		}

		return trim($text) . ' (' . $note . ')';
	}//end agentNote()
}//end class
