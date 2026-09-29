<?php

/**
 * Learniq Credential Renewal Listener
 *
 * Listens for OpenRegister's ObjectTransitionedEvent and, when a Credential
 * transitions to `expired` (via its `expire` transition), creates a new
 * Enrolment for the same learner/course the expiring credential attests
 * (`source: credential-renewal`, `mandatory: true`), then writes the new
 * Enrolment's id back onto `Credential.renewalEnrolmentId`.
 *
 * Credential.renewalEnrolmentId previously named a write path ("Written back
 * by OR batch") that did not exist anywhere in this codebase — the four
 * expiry-adjacent notifications (issuedToLearner/expiringSoon/expired/revoked)
 * were all real, only this side effect was missing.
 *
 * ADR-031 legitimate exception: cross-object create+link bridge — a
 * Credential expiring must create a new Enrolment. This cannot be expressed
 * as schema metadata declarations. Same category as ExemptionGrantHandler's
 * ExemptionCase-granted -> GradeEntry bridge.
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
 * @spec openspec/specs/certification/spec.md#requirement-auto-enrol-on-renewal-or-content-version-change
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\CredentialLearner;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Bridges Credential.expire -> renewal Enrolment create + link-back.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/certification/spec.md#requirement-auto-enrol-on-renewal-or-content-version-change
 */
class CredentialRenewalListener implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const CREDENTIAL_SCHEMA = 'credential';
	private const ENROLMENT_SCHEMA = 'enrolment';
	private const ACTION_EXPIRE = 'expire';
	private const ENROLMENT_SOURCE = 'credential-renewal';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
	 * @param CredentialLearner $learners The credential's learner as a Nextcloud user id.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly CredentialLearner $learners,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-auto-enrol-on-credential-expiry
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($event->getRegister() !== self::LEARNIQ_REGISTER
			|| $event->getSchema() !== self::CREDENTIAL_SCHEMA
			|| $event->getAction() !== self::ACTION_EXPIRE
		) {
			return;
		}

		$this->createRenewalEnrolment(credential: $event->getObject()->jsonSerialize());

	}//end handle()

	/**
	 * Create the renewal Enrolment and link it back onto the Credential.
	 *
	 * @param array<string,mixed> $credential The Credential data after the `expire` transition.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/certification/spec.md#scenario-auto-enrol-on-credential-expiry
	 */
	private function createRenewalEnrolment(array $credential): void {
		$credentialId = (string)($credential['id'] ?? ($credential['uuid'] ?? ''));
		$learnerId = (string)($credential['learnerId'] ?? '');
		$courseId = (string)($credential['courseId'] ?? '');
		$tenantId = (string)($credential['tenant_id'] ?? '');

		if ($learnerId === '' || $courseId === '' || $tenantId === '') {
			$this->logger->warning(
				'[CredentialRenewalListener] Credential {id} missing learnerId/courseId/tenant_id — skipping renewal.',
				['id' => $credentialId]
			);
			return;
		}

		// Credential.learnerId is the LearnerProfile uuid; Enrolment.learnerId
		// is the Nextcloud user id, and the uuid goes to Enrolment.learnerRef.
		$userId = $this->learners->userIdOf(credential: $credential);
		if ($userId === null) {
			$this->logger->warning(
				'[CredentialRenewalListener] Credential {id}: learner {learner} has no Nextcloud user — skipping renewal.',
				['id' => $credentialId, 'learner' => $learnerId, 'course' => $courseId]
			);
			return;
		}

		$enrolment = [
			'learnerId' => $userId,
			'courseId' => $courseId,
			'source' => self::ENROLMENT_SOURCE,
			'mandatory' => true,
			'regulationSlug' => $credential['regulationSlug'] ?? null,
			'tenant_id' => $tenantId,
		];
		if ($this->learners->isUuid(value: $learnerId) === true) {
			$enrolment['learnerRef'] = $learnerId;
		}

		$saved = $this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::ENROLMENT_SCHEMA,
			object: $enrolment
		);

		$savedData = $saved->jsonSerialize();
		$enrolmentId = $savedData['id'] ?? ($savedData['uuid'] ?? null);

		if ($enrolmentId === null) {
			$this->logger->warning(
				'[CredentialRenewalListener] Credential {id} — created renewal Enrolment has no id; not linking.',
				['id' => $credentialId]
			);
			return;
		}

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::CREDENTIAL_SCHEMA,
			object: array_merge($credential, ['renewalEnrolmentId' => $enrolmentId])
		);

		$this->logger->info(
			'[CredentialRenewalListener] Credential {id} expired — created renewal Enrolment {enrolmentId}.',
			['id' => $credentialId, 'enrolmentId' => $enrolmentId]
		);

	}//end createRenewalEnrolment()
}//end class
