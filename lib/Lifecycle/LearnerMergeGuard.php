<?php

/**
 * Learniq Learner Merge Guard
 *
 * Guards the LearnerProfile `merge` transition (learniq#950): the merge may only
 * proceed when `mergedInto` names another, active LearnerProfile and the two
 * accounts do not both hold an open enrolment in the same course.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\Learniq\Service\LearnerMergeService;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Blocks a learner merge whose target is missing, inactive, itself, or conflicting.
 */
class LearnerMergeGuard implements LifecycleGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param LearnerMergeService $mergeService Merge validation.
	 * @param LoggerInterface     $logger       PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearnerMergeService $mergeService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$reason = $this->mergeService->refusalReason(profile: $object);
		if ($reason !== null) {
			$this->logger->info('[LearnerMergeGuard] Merge refused: {reason}.', ['reason' => $reason]);
			return GuardResult::deny(sprintf('This merge is refused: %s.', $reason));
		}

		return GuardResult::allow();
	}//end check()
}//end class
