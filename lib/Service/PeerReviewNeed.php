<?php

/**
 * Learniq peer review need.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/assignments/spec.md#scenario-round-robin-allocates-the-configured-reviewer-count-while-excluding-self
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * One Submission short of reviewers: how many it still needs and which
 * reviewers must not be picked (its own learners and those already assigned).
 *
 * @spec openspec/specs/assignments/spec.md#scenario-round-robin-allocates-the-configured-reviewer-count-while-excluding-self
 */
final class PeerReviewNeed {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $submission   The Submission data array.
	 * @param string              $submissionId UUID of the Submission.
	 * @param int                 $needed       Reviewers still needed.
	 * @param array<int,string>   $excluded     Reviewer ids that must not be picked.
	 */
	public function __construct(
		public readonly array $submission,
		public readonly string $submissionId,
		public readonly int $needed,
		public readonly array $excluded,
	) {
	}//end __construct()
}//end class
