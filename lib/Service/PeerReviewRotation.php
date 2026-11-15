<?php

/**
 * Learniq peer review rotation.
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
 * One allocation run over an Assignment's Submissions: the reviewer pool,
 * the target reviewer count per Submission, and the pool cursor, which is
 * shared across Submissions and never reset (round-robin cycling).
 *
 * @spec openspec/specs/assignments/spec.md#scenario-round-robin-allocates-the-configured-reviewer-count-while-excluding-self
 */
final class PeerReviewRotation {

	/**
	 * The next pool position to try.
	 *
	 * @var int
	 */
	public int $cursor = 0;

	/**
	 * Constructor.
	 *
	 * @param string            $assignmentId    UUID of the Assignment.
	 * @param array<int,string> $pool            The reviewer pool.
	 * @param int               $reviewersPerSub Target reviewer count per Submission.
	 */
	public function __construct(
		public readonly string $assignmentId,
		public readonly array $pool,
		public readonly int $reviewersPerSub,
	) {
	}//end __construct()
}//end class
