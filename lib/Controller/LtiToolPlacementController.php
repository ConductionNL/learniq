<?php

/**
 * Learniq LTI Tool Placement Controller
 *
 * Starts an LTI 1.3 launch of a tool placement by raising integriq's typed
 * `LtiLaunchRequestedEvent` (ADR-041). Integriq is the LTI platform: it signs
 * and holds every LTI token, and answers with the login initiation form
 * `{formActionUrl, method, fields}` that targets the tool's OIDC login URL.
 * Learniq builds, signs and reads no LTI token; it resolves the placement the
 * caller may open, describes the launch (user, role, course, return URL) and
 * hands integriq's form to the lesson player, which submits it.
 *
 * The event class is looked up by name, never imported, so learniq stays
 * installable without integriq: without it a launch answers 503.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/specs/course-management/spec.md#requirement-lessonplayer-delegates-the-lti-launch-to-integriq-through-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raises integriq's LTI launch event for a placement and returns its login form.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-lessonplayer-delegates-the-lti-launch-to-integriq-through-a-typed-event
 */
class LtiToolPlacementController extends Controller {

	/**
	 * Integriq's launch event (ADR-041), named by string.
	 *
	 * @var string
	 */
	public const LAUNCH_EVENT = 'OCA\Integriq\Event\LtiLaunchRequestedEvent';

	/**
	 * OpenRegister register slug that owns the Learniq schemas.
	 *
	 * @var string
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * OpenRegister schema slug for LtiToolPlacement.
	 *
	 * @var string
	 */
	private const PLACEMENT_SCHEMA = 'lti-tool-placement';

	/**
	 * Groups that launch a tool as an LTI Instructor; everyone else is a Learner.
	 *
	 * @var array<int, string>
	 */
	private const INSTRUCTOR_GROUPS = ['instructors', 'team-leads', 'compliance-officers'];

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request         The current request.
	 * @param IUserSession     $userSession     NC user session.
	 * @param ObjectService    $objectService   OR object access service.
	 * @param IEventDispatcher $eventDispatcher Raises integriq's launch event.
	 * @param IGroupManager    $groupManager    Decides the LTI role.
	 * @param IURLGenerator    $urlGenerator    Builds the return URL.
	 * @param LoggerInterface  $logger          PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IGroupManager $groupManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Start an LTI launch for a placement.
	 *
	 * 401 without a session, 404 for a placement the caller cannot read, 422 for
	 * a placement without a deployment, 503 without integriq or when nothing
	 * answered, 409 when integriq refused, else 200 with the login initiation
	 * form plus the placement's `launchMode`.
	 *
	 * @param string $placementId UUID of the LtiToolPlacement to launch.
	 *
	 * @return JSONResponse `{formActionUrl, method, fields, launchMode}`, or an error.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-lessonplayer-delegates-the-lti-launch-to-integriq-through-a-typed-event
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function launch(string $placementId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// Read with the caller's own rights: a placement they cannot read is not launched.
		$placement = $this->findObject(id: $placementId, schema: self::PLACEMENT_SCHEMA);
		if ($placement === null) {
			return new JSONResponse(data: ['error' => 'Placement not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$deploymentId = (string)($placement['openconnectorDeploymentId'] ?? '');
		if ($deploymentId === '') {
			return new JSONResponse(
				data: ['error' => 'This placement names no LTI deployment'],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$eventClass = $this->resolveEventClass(eventClass: self::LAUNCH_EVENT);
		if ($eventClass === null) {
			return new JSONResponse(
				data: ['error' => 'LTI tools need integriq, which is not installed'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$event = $this->buildEvent(eventClass: $eventClass, placementId: $placementId, placement: $placement, uid: $user->getUID());
		if ($event === null) {
			return new JSONResponse(data: ['error' => 'No LTI launch handler answered'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return $this->answer(event: $event, launchMode: (string)($placement['launchMode'] ?? 'resource-link'));
	}//end launch()

	/**
	 * Build and dispatch the launch event, or null when it cannot be raised.
	 *
	 * @param string               $eventClass  The resolved event class.
	 * @param string               $placementId The placement UUID.
	 * @param array<string, mixed> $placement   The placement.
	 * @param string               $uid         The caller.
	 *
	 * @return Event|null The dispatched event, or null.
	 */
	private function buildEvent(string $eventClass, string $placementId, array $placement, string $uid): ?Event {
		$messageType = 'LtiResourceLinkRequest';
		if (($placement['launchMode'] ?? 'resource-link') === 'deep-linking') {
			$messageType = 'LtiDeepLinkingRequest';
		}

		$courseId = $this->contextCourseId(placement: $placement);
		$course   = [];
		if ($courseId !== '') {
			$course = ($this->findObject(id: $courseId, schema: 'course') ?? []);
		}

		$returnPath = '/apps/learniq/courses/' . $courseId;
		if ((string)($placement['lessonId'] ?? '') !== '') {
			$returnPath = '/apps/learniq/lessons/' . $placement['lessonId'];
		}

		try {
			$event = new $eventClass(
				sourceApp: Application::APP_ID,
				placementId: $placementId,
				deploymentUuid: (string)$placement['openconnectorDeploymentId'],
				userId: $uid,
				messageType: $messageType,
				role: $this->roleFor(uid: $uid),
				contextId: $courseId,
				contextTitle: (string)($course['name'] ?? ''),
				returnUrl: $this->urlGenerator->getAbsoluteURL($returnPath),
			);
			if (($event instanceof Event) === false) {
				return null;
			}

			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->error('[LtiToolPlacementController] raising the LTI launch event failed: {msg}', ['msg' => $e->getMessage()]);
			return null;
		}

		return $event;
	}//end buildEvent()

	/**
	 * The course a launch runs in, for the LTI context claim.
	 *
	 * A course-level placement names its course. A lesson-level placement has no
	 * `courseId` (the schema leaves it null), so the course is the lesson's own,
	 * read with the caller's rights.
	 *
	 * @param array<string, mixed> $placement The placement.
	 *
	 * @return string The course UUID, or '' when neither names one.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-lessonplayer-delegates-the-lti-launch-to-integriq-through-a-typed-event
	 */
	private function contextCourseId(array $placement): string {
		$courseId = (string)($placement['courseId'] ?? '');
		$lessonId = (string)($placement['lessonId'] ?? '');
		if ($courseId !== '' || $lessonId === '') {
			return $courseId;
		}

		$lesson = ($this->findObject(id: $lessonId, schema: 'lesson') ?? []);

		return (string)($lesson['courseId'] ?? '');
	}//end contextCourseId()

	/**
	 * Turn the answered event into the response.
	 *
	 * @param Event  $event      The dispatched event.
	 * @param string $launchMode The placement's launch mode.
	 *
	 * @return JSONResponse The response.
	 */
	private function answer(Event $event, string $launchMode): JSONResponse {
		$refusal = null;
		if (method_exists($event, 'getRefusal') === true) {
			$refusal = $event->getRefusal();
		}

		if (is_array($refusal) === true) {
			return new JSONResponse(
				data: ['error' => (string)($refusal['reason'] ?? 'The launch was refused'), 'code' => (string)($refusal['code'] ?? '')],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		$form = null;
		if (method_exists($event, 'getLoginInitiation') === true) {
			$form = $event->getLoginInitiation();
		}

		if (is_array($form) === false || (string)($form['formActionUrl'] ?? '') === '') {
			return new JSONResponse(data: ['error' => 'No LTI launch handler answered'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(
			data: [
				'formActionUrl' => (string)$form['formActionUrl'],
				'method'        => strtoupper((string)($form['method'] ?? 'POST')),
				'fields'        => (array)($form['fields'] ?? []),
				'launchMode'    => $launchMode,
			]
		);
	}//end answer()

	/**
	 * The LTI role of a user: Instructor for teaching staff, else Learner.
	 *
	 * @param string $uid The user id.
	 *
	 * @return string `Instructor` or `Learner`.
	 */
	private function roleFor(string $uid): string {
		foreach (self::INSTRUCTOR_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return 'Instructor';
			}
		}

		return 'Learner';
	}//end roleFor()

	/**
	 * Read one learniq object with the caller's rights, or null.
	 *
	 * @param string $id     The object UUID.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null The object, or null when absent or not readable.
	 */
	private function findObject(string $id, string $schema): ?array {
		try {
			$object = $this->objectService->find(id: $id, register: self::LEARNIQ_REGISTER, schema: $schema);
		} catch (Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end findObject()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name, or null when absent.
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()
}//end class
