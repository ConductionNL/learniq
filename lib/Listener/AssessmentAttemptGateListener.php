<?php

/**
 * Learniq Assessment Attempt Gate Listener
 *
 * Refuses to create an AssessmentResult (start an attempt) outside the
 * Assessment's availableFrom/availableUntil window, or without the right
 * access code when the Assessment has one. Before this listener nothing on the
 * server checked either: TakeAssessmentView created an `in-progress` result
 * whenever it was opened, so a learner could read an exam's questions before
 * it opened or after it closed (learniq#946).
 *
 * Same shape as EnrolmentPrerequisiteListener: a creation-time veto on
 * OpenRegister's `ObjectCreatingEvent`, because a lifecycle `requires` guard
 * never runs before an object's first insert. Two deliberate differences:
 *
 * - It FAILS CLOSED. When the Assessment cannot be read, the attempt is
 *   refused: an outage that delays an exam is recoverable, an exam that opens
 *   early is not.
 * - It resolves the schema through ListenerSchemaResolver::guardSchemaSlug(),
 *   which ignores the default-off listener slug contract. A security guard
 *   that runs only after an admin opts in does not protect anything.
 *
 * Admins and system context (no user session: occ, background jobs) bypass
 * the gate, so seeding and corrections keep working.
 *
 * Every create it lets through, admins' included, then has its read audience
 * stamped by AssessmentResultAudience (learniq#949) and its portal scope and
 * title by AssessmentResultPortalStamp (assessment-portal-endpoints). That
 * lives here rather than in listeners of their own so the pre-write steps run
 * in a fixed order and a refused attempt is never stamped.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\AssessmentAccessPolicy;
use OCA\Learniq\Service\AssessmentResultAudience;
use OCA\Learniq\Service\AssessmentResultPortalStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vetoes AssessmentResult creation outside the window or without the access code.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
 */
class AssessmentAttemptGateListener implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const RESULT_SCHEMA = 'assessment-result';
	private const ASSESSMENT_SCHEMA = 'exam';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema slug.
	 * @param IUserSession $userSession NC user session.
	 * @param IGroupManager $groupManager NC group manager (admin check).
	 * @param ITimeFactory $timeFactory Clock.
	 * @param AssessmentAccessPolicy $policy Window and access-code rules.
	 * @param AssessmentResultAudience $audience Stamps who may read the attempt (learniq#949).
	 * @param AssessmentResultPortalStamp $portalStamp Stamps learnerRef and the test's title for the portal.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ITimeFactory $timeFactory,
		private readonly AssessmentAccessPolicy $policy,
		private readonly AssessmentResultAudience $audience,
		private readonly AssessmentResultPortalStamp $portalStamp,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OR object-creating event, filtering to `assessment-result`.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false) {
			return;
		}

		$entity = $event->getObject();
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never break
			// another app's writes.
			return;
		}

		if ($slug !== self::RESULT_SCHEMA) {
			return;
		}

		if ($this->callerBypasses() === false) {
			$this->evaluate(event: $event, payload: $entity->jsonSerialize());
		}

		// Every attempt that is let through, admins' included, gets its read
		// audience stamped by the server (learniq#949), then the portal's scope
		// key and the test's title (assessment-portal-endpoints).
		if ($event->isPropagationStopped() === false) {
			$this->audience->stamp(event: $event);
			$this->portalStamp->stamp(event: $event);
		}
	}//end handle()

	/**
	 * Check one AssessmentResult create against its Assessment's window and
	 * access code, refusing it or clearing the typed code.
	 *
	 * @param ObjectCreatingEvent $event The event to stop or amend.
	 * @param array<string, mixed> $payload The AssessmentResult being created.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
	 */
	private function evaluate(ObjectCreatingEvent $event, array $payload): void {
		$assessmentId = (string)($payload['assessmentId'] ?? '');
		if ($assessmentId === '') {
			// OR's own `required` validation rejects a missing assessmentId.
			return;
		}

		$assessment = $this->loadAssessment(id: $assessmentId);
		if ($assessment === null) {
			$this->reject(
				event: $event,
				block: [
					'reason' => 'assessment-unavailable',
					'message' => 'This assessment could not be checked for availability, so it cannot be started. Try again later.',
				]
			);
			return;
		}

		$block = $this->policy->windowBlock(assessment: $assessment, now: $this->timeFactory->getDateTime());
		if ($block === null) {
			$block = $this->policy->accessCodeBlock(assessment: $assessment, given: ($payload['accessCode'] ?? null));
		}

		if ($block !== null) {
			$this->reject(event: $event, block: $block);
			return;
		}

		if (array_key_exists('accessCode', $payload) === true) {
			// The typed code proved access; it is not kept on the attempt.
			$event->setModifiedData(array_merge($event->getModifiedData(), ['accessCode' => null]));
		}
	}//end evaluate()

	/**
	 * Whether the caller is exempt: system context or a Nextcloud admin.
	 *
	 * @return bool
	 */
	private function callerBypasses(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return true;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end callerBypasses()

	/**
	 * Read the Assessment raw (no render, no RBAC) so the write-only access
	 * code is present. Null when it does not exist or cannot be read.
	 *
	 * @param string $id UUID of the Assessment.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadAssessment(string $id): ?array {
		try {
			$object = $this->objectService->find(
				id: $id,
				register: self::LEARNIQ_REGISTER,
				schema: self::ASSESSMENT_SCHEMA,
				_rbac: false,
				_render: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[AssessmentAttemptGateListener] Could not read assessment {id}: {msg}',
				['id' => $id, 'msg' => $exception->getMessage()]
			);
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end loadAssessment()

	/**
	 * Stop the create with a reason the frontend can show.
	 *
	 * @param ObjectCreatingEvent $event The event to stop.
	 * @param array{reason: string, message: string} $block Why.
	 *
	 * @return void
	 */
	private function reject(ObjectCreatingEvent $event, array $block): void {
		$event->setErrors($block);
		$event->stopPropagation();

		$this->logger->info(
			'[AssessmentAttemptGateListener] Refused an attempt: {reason}',
			['reason' => $block['reason']]
		);
	}//end reject()
}//end class
