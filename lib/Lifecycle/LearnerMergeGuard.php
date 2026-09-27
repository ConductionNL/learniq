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
use Psr\Log\LoggerInterface;

/**
 * Blocks a learner merge whose target is missing, inactive, itself, or conflicting.
 */
class LearnerMergeGuard {
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
	 * OR lifecycle guard entry-point for LearnerProfile `merge`.
	 *
	 * @param array<string,mixed> $transitionContext Context from OR's lifecycle engine;
	 *                                               'object' is the LearnerProfile data.
	 *
	 * @return bool True when the merge may proceed.
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function check(array &$transitionContext): bool {
		$profile = $transitionContext['object'] ?? [];
		if (is_array($profile) === false) {
			return false;
		}

		$reason = $this->mergeService->refusalReason(profile: $profile);
		if ($reason !== null) {
			$this->logger->info('[LearnerMergeGuard] Merge refused: {reason}.', ['reason' => $reason]);
			return false;
		}

		return true;
	}//end check()
}//end class
