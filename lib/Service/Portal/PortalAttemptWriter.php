<?php

/**
 * Learniq Portal Attempt Writer
 *
 * Every write the portal assessment endpoints make. Each runs as the pupil's
 * own account (ObjectService::runAs()), which narrows rather than elevates:
 * the attempt gate, the integrity listener and RBAC apply exactly as for the
 * pupil in the app. `runAsSystem()` is not used: OpenRegister keeps it out of
 * inbound request handling (ADR-099).
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Pupil-scoped writes for portal attempts.
 *
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */
class PortalAttemptWriter {

	private const REGISTER = 'learniq';
	private const RESULT_SCHEMA = 'assessment-result';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param TransitionEngine $transitionEngine OpenRegister lifecycle transitions.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TransitionEngine $transitionEngine,
	) {
	}//end __construct()

	/**
	 * Create an attempt as the pupil.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param array<string, mixed> $data The new AssessmentResult.
	 *
	 * @return array<string, mixed> The created attempt.
	 *
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	public function createAttempt(PortalLearner $learner, array $data): array {
		$saved = $this->objectService->runAs(
			user: $learner->user,
			operation: fn () => $this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::RESULT_SCHEMA
			)
		);

		return $saved->jsonSerialize();
	}//end createAttempt()

	/**
	 * Save an existing attempt as the pupil.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $id The attempt's uuid.
	 * @param array<string, mixed> $row The attempt as it should be stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	public function saveAttempt(PortalLearner $learner, string $id, array $row): void {
		unset($row['@self']);

		$this->objectService->runAs(
			user: $learner->user,
			operation: fn () => $this->objectService->saveObject(
				object: $row,
				register: self::REGISTER,
				schema: self::RESULT_SCHEMA,
				uuid: $id
			)
		);
	}//end saveAttempt()

	/**
	 * Fire the attempt's `submit` transition as the pupil, which runs
	 * AssessmentScoringHandler and AssessmentAutoScoreAction.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $id The attempt's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	public function fireSubmit(PortalLearner $learner, string $id): void {
		$this->objectService->runAs(
			user: $learner->user,
			operation: fn () => $this->transitionEngine->transition(objectId: $id, action: 'submit')
		);
	}//end fireSubmit()
}//end class
