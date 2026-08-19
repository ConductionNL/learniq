<?php

/**
 * Learniq MCP Tool Provider
 *
 * Per-app implementation of OCA\OpenRegister\Mcp\IMcpToolProvider for Learniq
 * (LVS + LMS). Exposes a small, privacy-conscious set of read-only MCP tools so
 * the AI Chat Companion (hydra ADR-034 + ADR-035) can surface Learniq's course
 * catalogue to an LLM — without ever leaking enrolled-learner PII.
 *
 * @category Mcp
 * @package  OCA\Learniq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Mcp;

use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Learniq MCP Tool Provider.
 *
 * Implements IMcpToolProvider (from openregister PR #1466,
 * change ai-chat-companion-orchestrator) exposing 2 read-only course tools to
 * the AI Chat Companion. Learniq handles student data, which is privacy
 * sensitive — so the MVP deliberately ships ONLY the two least sensitive tools
 * (the course catalogue and a course's module structure). Tools that touch
 * learner records, enrolments, attestations or credentials are deferred to a
 * follow-up that wires proper per-student authorisation (REQ: a teacher of that
 * learner's group, the learner themself, or an admin).
 *
 * Auth design (OWASP A01:2021 / ADR-005):
 * - Per-object authorisation runs inside invokeTool(), AFTER argument validation
 *   but BEFORE business logic. The helper invoked MUST actually run.
 * - requireCourseReadAccess() returns bool — it does NOT return true
 *   unconditionally and is NOT wrapped in catch(\Throwable). It requires an
 *   authenticated Nextcloud user; OpenRegister's RBAC layer (applied inside
 *   ObjectService) is the second gate that scopes which course objects are
 *   visible to that user.
 * - getCourseDetails() returns only the course metadata + the module (Lesson)
 *   structure; it never returns Enrolment, Attestation, Credential or learner
 *   objects, so no per-learner PII can leak through this provider.
 */
class LearniqToolProvider implements IMcpToolProvider {

	/**
	 * The Learniq OpenRegister register slug.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'learniq';

	/**
	 * The Course schema slug/name in the Learniq register.
	 *
	 * @var string
	 */
	private const SCHEMA_COURSE = 'course';

	/**
	 * The Lesson (module) schema slug/name in the Learniq register.
	 *
	 * @var string
	 */
	private const SCHEMA_LESSON = 'lesson';

	/**
	 * Maximum number of items returned by a list tool.
	 *
	 * @var int
	 */
	private const LIST_CAP = 20;

	/**
	 * Tool catalogue.
	 *
	 * Hard-coded as a constant so unit tests can assert it as a fixture.
	 * Exactly two read-only MVP tools — the least privacy-sensitive surface.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const TOOL_DESCRIPTORS = [
		[
			'id' => 'learniq.listCourses',
			'subject' => 'course',
			'action' => 'list',
			'name' => 'List courses',
			'description' => 'List Learniq courses visible to you. Catalogue only, no learner data. Optional status: draft/published/archived.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'limit' => [
						'type' => 'integer',
						'minimum' => 1,
						'maximum' => 50,
						'default' => 20,
					],
					'status' => [
						'type' => 'string',
						'enum' => ['draft', 'published', 'archived'],
					],
				],
				'required' => [],
			],
		],
		[
			'id' => 'learniq.getCourseDetails',
			'subject' => 'course',
			'action' => 'get',
			'name' => 'Get course details',
			'description' => 'Get one Learniq course by id, uuid or slug with its module list. Course and module metadata only, no learner data.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'id' => [
						'type' => 'string',
						'minLength' => 1,
						'description' => 'Course id, uuid or slug.',
					],
				],
				'required' => ['id'],
			],
		],
	];

	/**
	 * Constructor for LearniqToolProvider.
	 *
	 * @param ObjectService $objectService The OpenRegister object service (reads).
	 * @param IUserSession $userSession The current user session.
	 * @param IGroupManager $groupManager The group manager (for admin checks).
	 * @param LoggerInterface $logger The PSR-3 logger.
	 * @param CourseToolPresenter $presenter Normalises OR objects into the privacy-safe MCP payloads.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly CourseToolPresenter $presenter,
	) {
	}//end __construct()

	/**
	 * Returns the app ID that namespaces every tool id.
	 *
	 * @return string "learniq"
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-1
	 */
	public function getAppId(): string {
		return 'learniq';
	}//end getAppId()

	/**
	 * Returns the full tool catalogue (2 tools, always).
	 *
	 * The full catalogue is always returned regardless of caller permissions.
	 * Per-object authorisation runs in invokeTool().
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-1
	 */
	public function getTools(): array {
		return self::TOOL_DESCRIPTORS;
	}//end getTools()

	/**
	 * Dispatch a tool call by id.
	 *
	 * Argument validation runs BEFORE authorisation, which runs BEFORE business
	 * logic. Unknown tool ids return a structured error; no exception is thrown.
	 *
	 * @param string $toolId The tool id (e.g. "learniq.listCourses").
	 * @param array<string, mixed> $arguments Tool arguments from the LLM call.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-2
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		return match ($toolId) {
			'learniq.listCourses' => $this->handleListCourses(args: $arguments),
			'learniq.getCourseDetails' => $this->handleGetCourseDetails(args: $arguments),
			default => [
				'isError' => true,
				'error' => 'unknown_tool',
				'message' => "Unknown tool id '{$toolId}'. Available tools: "
					. implode(separator: ', ', array: array_column(array: self::TOOL_DESCRIPTORS, column_key: 'id')) . '.',
			],
		};

	}//end invokeTool()

	// =========================================================================
	// Private tool handlers
	// =========================================================================

	/**
	 * Handle learniq.listCourses.
	 *
	 * Returns the course catalogue (capped at LIST_CAP), optionally filtered by
	 * lifecycle status. No learner data is included.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-3
	 */
	private function handleListCourses(array $args): array {
		$validated = $this->validateListCoursesArgs(args: $args);
		if (isset($validated['error']) === true) {
			return $validated['error'];
		}

		// Authorisation BEFORE business logic — must be an authenticated user.
		if ($this->requireCourseReadAccess() === false) {
			return [
				'isError' => true,
				'error' => 'forbidden',
				'message' => 'You must be signed in to list courses.',
			];
		}

		$config = $this->buildCourseListConfig(validated: $validated);
		if (isset($config['error']) === true) {
			return $config['error'];
		}

		try {
			$rawCourses = $this->objectService->findAll($config);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Learniq MCP: listCourses failed',
				['exception' => $e->getMessage()]
			);
			return [
				'isError' => true,
				'error' => 'internal_error',
				'message' => 'Failed to retrieve courses. See server log for details.',
			];
		}

		$courses = [];
		$sources = [];
		foreach ($rawCourses as $raw) {
			$course = $this->presenter->toArray(item: $raw);
			$courseUuid = $this->presenter->extractUuid(item: $course);
			$courses[] = $this->presenter->courseSummary(course: $course);
			$sources[] = $this->presenter->courseSource(course: $course, courseUuid: $courseUuid);
		}

		return [
			'success' => true,
			'courses' => $courses,
			'sources' => $sources,
		];

	}//end handleListCourses()

	/**
	 * Build the `ObjectService::findAll()` config for a listCourses call, or the
	 * error response when the caller may not ask for what they asked for.
	 *
	 * M2: non-admin callers must only see published courses — they must not be
	 * able to discover draft or archived courses through the MCP surface, either
	 * by filtering for them explicitly or by omitting the filter.
	 *
	 * @param array{limit?: int, status?: string|null} $validated Validated listCourses arguments.
	 *
	 * @return array<string, mixed> The findAll config, or `['error' => <response>]`.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-3
	 */
	private function buildCourseListConfig(array $validated): array {
		$callerIsAdmin = $this->callerIsAdmin();
		$status = ($validated['status'] ?? null);

		// Hard cap at LIST_CAP regardless of the requested limit.
		$config = [
			'register' => self::REGISTER_SLUG,
			'schema' => self::SCHEMA_COURSE,
			'limit' => min((int)$validated['limit'], self::LIST_CAP),
		];

		if ($status === null) {
			// No status requested by a non-admin — restrict to published only.
			if ($callerIsAdmin === false) {
				$config['filters'] = ['lifecycle' => 'published'];
			}

			return $config;
		}

		// Respect the requested status filter, but non-admins can only request 'published'.
		if ($callerIsAdmin === false && $status !== 'published') {
			return [
				'error' => [
					'isError' => true,
					'error' => 'forbidden',
					'message' => "Status filter '{$status}' is not available to non-admin users.",
				],
			];
		}

		$config['filters'] = ['lifecycle' => $status];

		return $config;
	}//end buildCourseListConfig()

	/**
	 * Whether the caller of the current MCP request is a Nextcloud admin.
	 *
	 * An unauthenticated caller is never an admin, so every MCP surface that
	 * gates draft/archived content asks this one question.
	 *
	 * @return bool True when a signed-in admin is making the call.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-3
	 */
	private function callerIsAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end callerIsAdmin()

	/**
	 * Validate learniq.listCourses arguments.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 *
	 * @return array{error?: array<string, mixed>, limit?: int, status?: string|null}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-3
	 */
	private function validateListCoursesArgs(array $args): array {
		$limit = 20;
		if (isset($args['limit']) === true) {
			$limit = (int)$args['limit'];
		}

		if ($limit < 1 || $limit > 50) {
			return [
				'error' => [
					'isError' => true,
					'error' => 'invalid_arguments',
					'message' => "Invalid limit {$limit}. Must be between 1 and 50.",
				],
			];
		}

		$status = $args['status'] ?? null;
		if ($status !== null) {
			$validStatuses = ['draft', 'published', 'archived'];
			if (in_array(needle: $status, haystack: $validStatuses, strict: true) === false) {
				return [
					'error' => [
						'isError' => true,
						'error' => 'invalid_arguments',
						'message' => "Invalid status '{$status}'. Allowed: " . implode(separator: ', ', array: $validStatuses) . '.',
					],
				];
			}
		}

		$normalisedStatus = null;
		if ($status !== null) {
			$normalisedStatus = (string)$status;
		}

		return [
			'limit' => $limit,
			'status' => $normalisedStatus,
		];

	}//end validateListCoursesArgs()

	/**
	 * Handle learniq.getCourseDetails.
	 *
	 * Fetches one course by id/uuid/slug with its ordered module (Lesson)
	 * structure. Returns only course metadata + module metadata — never
	 * Enrolment, Attestation, Credential or learner objects.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-4
	 */
	private function handleGetCourseDetails(array $args): array {
		$rawId = $args['id'] ?? null;
		if ($rawId === null || (string)$rawId === '') {
			return [
				'isError' => true,
				'error' => 'invalid_arguments',
				'message' => 'Required argument id is missing.',
			];
		}

		$courseRef = (string)$rawId;

		// Authorisation BEFORE business logic — must be an authenticated user.
		if ($this->requireCourseReadAccess() === false) {
			return [
				'isError' => true,
				'error' => 'forbidden',
				'message' => 'You must be signed in to view course details.',
			];
		}

		try {
			$course = $this->findCourse(courseRef: $courseRef);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Learniq MCP: getCourseDetails lookup failed',
				['courseRef' => $courseRef, 'exception' => $e->getMessage()]
			);
			return [
				'isError' => true,
				'error' => 'internal_error',
				'message' => 'Failed to retrieve course. See server log for details.',
			];
		}

		if ($course === null) {
			return [
				'isError' => true,
				'error' => 'not_found',
				'message' => 'Course not found.',
			];
		}

		// #197: non-admin learners must not see draft courses via MCP. Admins may
		// view drafts; regular authenticated users see only published courses. The
		// refusal is 'not_found', not 'forbidden', so the existence of a draft is
		// never leaked to a learner.
		$courseLifecycle = ($course['lifecycle'] ?? ($course['status'] ?? 'published'));
		if ($courseLifecycle !== 'published' && $this->callerIsAdmin() === false) {
			return [
				'isError' => true,
				'error' => 'not_found',
				'message' => 'Course not found.',
			];
		}

		$courseUuid = $this->presenter->extractUuid(item: $course);
		$modules = $this->loadCourseModules(courseUuid: $courseUuid);

		return [
			'success' => true,
			'course' => $this->presenter->courseSummary(course: $course),
			'modules' => $modules,
			'sources' => $this->presenter->buildCourseDetailSources(
				course: $course,
				courseUuid: $courseUuid,
				modules: $modules
			),
		];

	}//end handleGetCourseDetails()

	/**
	 * Load and order the published-or-draft module (Lesson) summaries for a course.
	 *
	 * Returns only module metadata — never Enrolment/Attestation/Credential/learner
	 * data. On a lookup failure an empty list is returned (course details still render).
	 *
	 * @param string $courseUuid The parent course UUID.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-4
	 */
	private function loadCourseModules(string $courseUuid): array {
		try {
			$rawLessons = $this->objectService->findAll(
				[
					'register' => self::REGISTER_SLUG,
					'schema' => self::SCHEMA_LESSON,
					'filters' => ['courseId' => $courseUuid],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Learniq MCP: getCourseDetails module lookup failed',
				['courseUuid' => $courseUuid, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$modules = [];
		foreach ($rawLessons as $raw) {
			$modules[] = $this->presenter->moduleSummary(lesson: $this->presenter->toArray(item: $raw));
		}

		// Stable ordering by the 1-based `order` field.
		usort(
			$modules,
			static function (array $a, array $b): int {
				return (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0);
			}
		);

		return $modules;
	}//end loadCourseModules()

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Authorise read access to the Learniq course catalogue.
	 *
	 * Auth design (OWASP A01:2021 / ADR-005):
	 * - Requires an authenticated Nextcloud user. There is no anonymous access
	 *   — an unauthenticated caller is rejected here before any data is read.
	 * - This is the gate at the provider boundary; OpenRegister's own RBAC layer
	 *   (applied inside ObjectService.findAll / find) is the second, per-object
	 *   gate that scopes which course objects are visible to that user, and is
	 *   why both `_rbac` and `_multitenancy` are left at their default `true`.
	 * - System admins are explicitly allowed (defensive; the RBAC gate also
	 *   honours admin, but stating it here documents the intent).
	 * - This helper MUST actually run — it does not return true unconditionally
	 *   and is NOT wrapped in catch(\Throwable).
	 *
	 * @return bool True when the caller is an authenticated user.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-3
	 */
	private function requireCourseReadAccess(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$userId = $user->getUID();
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		// Authenticated non-admin user: allowed at the provider boundary;
		// OpenRegister RBAC inside ObjectService scopes the actual rows.
		return $userId !== '';
	}//end requireCourseReadAccess()

	/**
	 * Resolve a course by uuid, then by slug, then by course code.
	 *
	 * @param string $courseRef The course id/uuid/slug/code.
	 *
	 * @return array<string, mixed>|null The normalised course array, or null.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-4
	 */
	private function findCourse(string $courseRef): ?array {
		// Direct id/uuid lookup first (covers both UUID and internal-id refs).
		$entity = $this->objectService->find(
			id: $courseRef,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_COURSE
		);
		if ($entity !== null) {
			return $this->presenter->toArray(item: $entity);
		}

		// Fall back to a filtered search by slug then by course code.
		foreach (['slug', 'code'] as $field) {
			$matches = $this->objectService->findAll(
				[
					'register' => self::REGISTER_SLUG,
					'schema' => self::SCHEMA_COURSE,
					'filters' => [$field => $courseRef],
					'limit' => 1,
				]
			);
			if (empty($matches) === false) {
				return $this->presenter->toArray(item: $matches[0]);
			}
		}

		return null;
	}//end findCourse()
}//end class
