<?php

/**
 * Learniq exam sitting establish guard
 *
 * Only the exam committee establishes the results of a sitting. The guard
 * runs on ExamSitting.establish.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/exam-board/spec.md#requirement-the-committee-establishes-the-results-of-a-sitting
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Refuses establishing a sitting's results by anyone outside the exam committee.
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T2
 */
class ExamSittingEstablishGuard implements LifecycleGuardInterface {

	/**
	 * The groups that act as the exam committee: the committee itself
	 * (compliance-officers, as for exam objections and fraud cases) and admin.
	 *
	 * @var list<string>
	 */
	public const COMMITTEE_GROUPS = ['admin', 'compliance-officers'];

	/**
	 * The refusal shown to anyone else.
	 *
	 * @var string
	 */
	private const DENIAL = 'Only the exam committee can establish the results of a sitting.';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager   $groupManager Resolves the actor's groups.
	 * @param IUserManager    $userManager  Resolves the actor.
	 * @param LoggerInterface $logger       Logs a refusal.
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T2
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Allow the transition only for a member of the exam committee.
	 *
	 * @param array<string, mixed> $object The sitting.
	 * @param string               $action The transition name.
	 * @param string               $userId The acting user.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T2
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$groups = new ActorGroups(userManager: $this->userManager, groupManager: $this->groupManager);
		if ($userId !== '' && $groups->isInAny(actor: $userId, groups: self::COMMITTEE_GROUPS) === true) {
			return GuardResult::allow();
		}

		$this->logger->info(
			'[ExamSittingEstablishGuard] {actor} is not in the exam committee; establishing sitting {id} refused.',
			['actor' => $userId, 'id' => $object['id'] ?? ($object['uuid'] ?? '')]
		);

		return GuardResult::deny(self::DENIAL);
	}//end check()
}//end class
