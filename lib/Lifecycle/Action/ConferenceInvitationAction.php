<?php

/**
 * Learniq conference invitation action
 *
 * The `send-invitations` transition of a ConferenceRound computes who is
 * invited, once, from the round's cohorts (parent-conferences spec,
 * "Digital invitations are a declared transition notification to the
 * round's invited learners"). Nothing computed it: `invitedLearnerIds`
 * stayed empty, so the declared invitation notification had no recipients
 * and no guardian could see the round in the parent portal.
 *
 * This action fills `invitedLearnerIds` (the learners' Nextcloud user ids,
 * which the notification rule addresses) and `invitedLearnerRefs` (their
 * LearnerProfile uuids, which the parent portal scopes the round by).
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle\Action
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
 *
 * @spec openspec/specs/parent-conferences/spec.md#requirement-digital-invitations-are-a-declared-transition-notification-to-the-rounds-invited-learners
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle\Action;

use OCA\Learniq\Service\LearnerRefResolver;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills a round's invited learners from its cohorts.
 *
 * @spec openspec/specs/parent-conferences/spec.md#requirement-digital-invitations-are-a-declared-transition-notification-to-the-rounds-invited-learners
 */
class ConferenceInvitationAction implements LifecycleActionInterface {

	private const REGISTER = 'learniq';

	private const COHORT_SCHEMA = 'cohort';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the round's cohorts.
	 * @param LearnerRefResolver $profiles Nextcloud user id to LearnerProfile uuid.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LearnerRefResolver $profiles,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp `invitedLearnerIds` and `invitedLearnerRefs` from `cohortIds`.
	 *
	 * @param array<string, mixed> $objectData The round after the move.
	 * @param array<string, mixed> $previousData The round before the move.
	 * @param array<string, mixed> $parameters The declared parameters (none).
	 * @param string $actionName The declared action name.
	 *
	 * @return array<string, mixed> The round with its invited learners.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#requirement-digital-invitations-are-a-declared-transition-notification-to-the-rounds-invited-learners
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$tenantId = (string)($objectData['tenant_id'] ?? '');
		$ids = [];
		foreach ((array)($objectData['cohortIds'] ?? []) as $cohortId) {
			foreach ($this->learnersOf(cohortId: (string)$cohortId) as $learnerId) {
				$ids[$learnerId] = true;
			}
		}

		$refs = [];
		foreach (array_keys($ids) as $learnerId) {
			$ref = $this->profiles->resolveInTenant(learnerId: (string)$learnerId, tenantId: $tenantId);
			if ($ref !== null) {
				$refs[] = $ref;
			}
		}

		$objectData['invitedLearnerIds'] = array_map('strval', array_keys($ids));
		$objectData['invitedLearnerRefs'] = $refs;

		return $objectData;
	}//end execute()

	/**
	 * The Nextcloud user ids a cohort lists, or none when it cannot be read.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return array<int, string>
	 */
	private function learnersOf(string $cohortId): array {
		if ($cohortId === '') {
			return [];
		}

		try {
			$cohort = $this->objectService->find(
				id: $cohortId,
				register: self::REGISTER,
				schema: self::COHORT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ConferenceInvitationAction] Cohort {cohort} could not be read: {msg}',
				['cohort' => $cohortId, 'msg' => $exception->getMessage()]
			);
			return [];
		}

		$row = [];
		if ($cohort !== null) {
			$row = $cohort->jsonSerialize();
		}

		return array_values(
			array_filter(
				(array)($row['learnerIds'] ?? []),
				static fn ($id): bool => is_string($id) === true && $id !== ''
			)
		);
	}//end learnersOf()
}//end class
