<?php

/**
 * Learniq timetable import collaborators.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling
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
 * @spec openspec/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling;

use OCA\Learniq\Service\TimetableExchangeSettings;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;

/**
 * What a timetable import job reads and checks: the source resolver, the
 * group code maps and the conflict detector.
 *
 * @spec openspec/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
 */
final class TimetableImportCollaborators {

	/**
	 * Constructor.
	 *
	 * @param TimetableSourceResolver   $sources          Says whether planninq is the source; reads its lessons.
	 * @param TimetableExchangeSettings $groupMaps        The group code maps the administrator keeps per rostering system.
	 * @param TimetableConflictDetector $conflictDetector Scans the delivered lessons.
	 */
	public function __construct(
		public readonly TimetableSourceResolver $sources,
		public readonly TimetableExchangeSettings $groupMaps,
		public readonly TimetableConflictDetector $conflictDetector,
	) {
	}//end __construct()
}//end class
