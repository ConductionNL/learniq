<?php

/**
 * Scholiq Credential Issuance Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent on the Enrolment schema.
 * When the transition is `active → completed` and the associated Course has a
 * `certificateTemplate` configured, this handler calls CredentialSigningService
 * to build and sign an OB3 payload, then writes a new Credential object via OR
 * with `lifecycle=issued`, which triggers the OR-declared `issuedToLearner`
 * notification automatically.
 *
 * Legitimate PHP per ADR-031: "Lifecycle handler — event-to-object-write bridge
 * that cannot be expressed as a schema declaration." Single responsibility:
 * translate the Enrolment transition event into a Credential save. All subsequent
 * state management (expiry detection, notifications, lifecycle transitions) is
 * declared in the Credential schema in scholiq_register.json.
 *
 * @category Listener
 * @package  OCA\Scholiq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Bridges the OpenRegister Enrolment.completed transition to Credential issuance.
 *
 * @implements IEventListener<Event>
 */
class CredentialIssuanceHandler implements IEventListener {
	private const ENROLMENT_SCHEMA = 'enrolment';
	private const SCHOLIQ_REGISTER = 'scholiq';
	private const COMPLETED_STATE = 'completed';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads Course and writes Credential via OpenRegister.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * Only acts on Enrolment objects transitioning to `completed` within the
	 * scholiq register. When the related Course has `certificateTemplate` set,
	 * creates a Credential via OR — the `issue` lifecycle guard
	 * (CredentialSigningService) fires automatically via OR's declared requires[].
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false) {
			return;
		}

		// Only handle Enrolment transitions within the scholiq register.
		if ($this->isEnrolmentCompletion(event: $event) === false) {
			return;
		}

		$enrolment = $event->getObject()->jsonSerialize();
		$courseId = $enrolment['courseId'] ?? null;
		$learnerId = $enrolment['learnerId'] ?? '';
		$tenantId = $enrolment['tenant_id'] ?? '';
		$completedAt = $enrolment['completedAt'] ?? (new DateTimeImmutable())->format(\DATE_ATOM);

		if ($courseId === null || $learnerId === '' || $tenantId === '') {
			return;
		}

		// Read the Course to check for certificateTemplate.
		$courseObj = $this->objectService->find(
			id: $courseId,
			register: self::SCHOLIQ_REGISTER,
			schema: 'course'
		);

		if ($courseObj === null) {
			return;
		}

		$course = $courseObj->jsonSerialize();

		if (empty($course['certificateTemplate']) === true) {
			// No certificate template — do not issue (REQ-CE-001-B).
			return;
		}

		$enrolmentId = $enrolment['id'] ?? ($enrolment['uuid'] ?? null);

		// #181: idempotency guard — an admin re-save or an event replay must not
		// issue a duplicate credential.
		if ($this->credentialAlreadyIssued(enrolmentId: $enrolmentId) === true) {
			return;
		}

		$expiresAt = $this->resolveExpiresAt(course: $course, completedAt: (string)$completedAt);

		// C1 fix: Do NOT write `lifecycle` — let OR auto-fire the `issue` transition
		// from null (initial: issued) which invokes the `requires:` guard
		// (CredentialSigningService::check()) before persisting the object.
		// Writing any lifecycle value directly bypasses the signing guard.
		$this->objectService->saveObject(
			register: self::SCHOLIQ_REGISTER,
			schema: 'credential',
			object: [
				'learnerId' => $learnerId,
				'courseId' => $courseId,
				'enrolmentId' => $enrolmentId,
				'kind' => 'certificate',
				'issuedAt' => $completedAt,
				'expiresAt' => $expiresAt,
				'issuedBy' => $course['issuerName'] ?? '',
				'source' => 'auto',
				'regulationSlug' => $course['regulationSlug'] ?? null,
				'tenant_id' => $tenantId,
			]
		);
	}//end handle()

	/**
	 * Whether this transition is a scholiq Enrolment entering `completed`.
	 *
	 * @param ObjectTransitionedEvent $event The transition event.
	 *
	 * @return bool True when this handler should act on it.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-11
	 */
	private function isEnrolmentCompletion(ObjectTransitionedEvent $event): bool {
		if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
			return false;
		}

		if ($event->getSchema() !== self::ENROLMENT_SCHEMA) {
			return false;
		}

		return ($event->getTo() === self::COMPLETED_STATE);
	}//end isEnrolmentCompletion()

	/**
	 * Whether an auto-issued Credential already exists for this enrolment.
	 *
	 * #181: without this, an admin re-save or a replayed event mints a second
	 * certificate for the same completion. An enrolment with no id cannot be
	 * checked, so it is allowed through rather than blocked.
	 *
	 * @param mixed $enrolmentId The Enrolment id, when it has one.
	 *
	 * @return bool True when a credential has already been issued.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-11
	 */
	private function credentialAlreadyIssued(mixed $enrolmentId): bool {
		if ($enrolmentId === null) {
			return false;
		}

		$existing = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => 'credential',
				'filters' => [
					'enrolmentId' => $enrolmentId,
					'source' => 'auto',
				],
				'limit' => 1,
			]
		);

		return (empty($existing) === false);
	}//end credentialAlreadyIssued()

	/**
	 * Calculate the credential's expiry from the course's validity period.
	 *
	 * @param array<string,mixed> $course The Course being certified.
	 * @param string $completedAt When the enrolment completed.
	 *
	 * @return string|null The expiry timestamp, or null when the course sets no validity period.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-11
	 */
	private function resolveExpiresAt(array $course, string $completedAt): ?string {
		if (empty($course['defaultExpiresAfterDays']) === true) {
			return null;
		}

		return (new DateTimeImmutable($completedAt))
			->modify('+' . (int)$course['defaultExpiresAfterDays'] . ' days')
			->format(\DATE_ATOM);

	}//end resolveExpiresAt()
}//end class
