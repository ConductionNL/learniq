<?php

/**
 * Learniq Credential Issuance Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent on the Enrolment schema.
 * When the transition is `active → completed` and the associated Course has a
 * `certificateTemplate` configured, this handler calls CredentialSigningService
 * to build and sign an OB3 payload, then writes the signed Credential via OR.
 * OR sets `lifecycle` to the schema's initial `issued`, and the created object
 * triggers the declared `issuedToLearner` notification.
 *
 * The signing happens here, before the save, because OpenRegister runs
 * lifecycle guards and actions on updates only: nothing signs a credential on
 * create otherwise, and the schema requires the signed fields (learniq#182).
 *
 * Legitimate PHP per ADR-031: "Lifecycle handler — event-to-object-write bridge
 * that cannot be expressed as a schema declaration." Single responsibility:
 * translate the Enrolment transition event into a Credential save. All subsequent
 * state management (expiry detection, notifications, lifecycle transitions) is
 * declared in the Credential schema in learniq_register.json.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Service\CredentialSigningService;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Bridges the OpenRegister Enrolment.completed transition to Credential issuance.
 *
 * @implements IEventListener<Event>
 */
class CredentialIssuanceHandler implements IEventListener {
	private const ENROLMENT_SCHEMA = 'enrolment';
	private const LEARNIQ_REGISTER = 'learniq';
	private const COMPLETED_STATE = 'completed';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads Course and School, writes Credential via OpenRegister.
	 * @param CredentialSigningService $signingService Signs the credential before it is saved.
	 * @param LoggerInterface $logger Records a credential that could not be signed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CredentialSigningService $signingService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * Only acts on Enrolment objects transitioning to `completed` within the
	 * learniq register. When the related Course has `certificateTemplate` set,
	 * signs a Credential and creates it via OR. A credential that cannot be
	 * signed is not saved: the public verify route could never verify it.
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

		// Only handle Enrolment transitions within the learniq register.
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
			register: self::LEARNIQ_REGISTER,
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

		$this->saveSignedCredential(
			credential: [
				'learnerId' => $learnerId,
				'courseId' => $courseId,
				'enrolmentId' => $enrolmentId,
				'kind' => 'certificate',
				'issuedAt' => $completedAt,
				'expiresAt' => $expiresAt,
				'issuedBy' => $this->resolveIssuerName(tenantId: (string)$tenantId),
				'source' => 'auto',
				'regulationSlug' => $course['regulationSlug'] ?? null,
				'tenant_id' => $tenantId,
			]
		);
	}//end handle()

	/**
	 * Sign a credential and save it under the uuid the signature covers.
	 *
	 * Signs before the save: OR runs no lifecycle guard or action on a create
	 * (learniq#182), and `signature`, `openbadges3Payload` and `issuerDid` are
	 * required. `lifecycle` is left to OR's declared initial `issued`. A
	 * credential that cannot be signed is logged and not saved.
	 *
	 * @param array<string, mixed> $credential The unsigned credential fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	private function saveSignedCredential(array $credential): void {
		$signed = $this->signingService->sign(credential: $credential);

		if ($signed === null) {
			$this->logger->error(
				'Learniq: no credential issued for enrolment {enrolment}: it could not be signed. '
				. 'Generate the credential signing key for tenant {tenant} in the Learniq admin settings.',
				['enrolment' => $credential['enrolmentId'], 'tenant' => $credential['tenant_id']]
			);
			return;
		}

		$credentialId = (string)$signed['id'];
		unset($signed['id']);

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: 'credential',
			object: $signed,
			uuid: $credentialId
		);
	}//end saveSignedCredential()

	/**
	 * The issuing organisation's display name: the tenant's School `name`.
	 *
	 * Every segment's organisation is a School record (the company and training
	 * example sets included). The Course schema declares no issuer field, so the
	 * former read of `Course.issuerName` always gave an empty `issuedBy`.
	 *
	 * @param string $tenantId The tenant the credential is issued in.
	 *
	 * @return string The School name, or '' when the tenant has no School record.
	 *
	 * @spec openspec/changes/credentials-europass-edci-export/tasks.md#task-1-prove-or-repair-the-signing-path
	 */
	private function resolveIssuerName(string $tenantId): string {
		$schools = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => 'school',
					'tenant_id' => $tenantId,
				],
				'limit' => 1,
			]
		);

		if (empty($schools) === true) {
			return '';
		}

		$school = $schools[0];
		if (is_array($school) === false) {
			$school = $school->jsonSerialize();
		}

		return (string)($school['name'] ?? '');
	}//end resolveIssuerName()

	/**
	 * Whether this transition is a learniq Enrolment entering `completed`.
	 *
	 * @param ObjectTransitionedEvent $event The transition event.
	 *
	 * @return bool True when this handler should act on it.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-11
	 */
	private function isEnrolmentCompletion(ObjectTransitionedEvent $event): bool {
		if ($event->getRegister() !== self::LEARNIQ_REGISTER) {
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
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => 'credential',
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
