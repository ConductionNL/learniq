<?php

/**
 * Learniq attainment evidence.
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
 * @spec openspec/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * The evidence one roll-up adds to a competency attainment: the ids to
 * append, and the percentage or the already resolved level it supports.
 *
 * @spec openspec/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
 */
final class AttainmentEvidence {

	/**
	 * Constructor.
	 *
	 * @param array<string,string|null> $append  Map of evidence-array field name to the id to append.
	 * @param float|null                $percent Evidence percentage for threshold-based level resolution, or null.
	 * @param string|null               $levelId An already resolved levelId; when null, the percentage decides.
	 */
	public function __construct(
		public readonly array $append,
		public readonly ?float $percent = null,
		public readonly ?string $levelId = null,
	) {
	}//end __construct()
}//end class
