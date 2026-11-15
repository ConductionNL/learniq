<?php

/**
 * Learniq Correction Publish
 *
 * One publish of a grade entry by one person, as CorrectionApprovals judges
 * it against the approved correction requests.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Grading
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Grading;

/**
 * The grade entry being published, its uuid and the person publishing.
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */
final class CorrectionPublish {

	/**
	 * The grade entry uuid: its `id`, else its `uuid`, else ''.
	 *
	 * @var string
	 */
	public readonly string $entryId;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $entry     The grade entry at its target state.
	 * @param string               $publisher The uid of the person publishing.
	 */
	public function __construct(
		public readonly array $entry,
		public readonly string $publisher,
	) {
		$this->entryId = (string)($entry['id'] ?? ($entry['uuid'] ?? ''));
	}//end __construct()
}//end class
