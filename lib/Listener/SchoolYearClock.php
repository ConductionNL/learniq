<?php

/**
 * Learniq school year clock.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\Calendar\SchoolYear;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The school year as the school sets it and the server clock that says which day it is.
 *
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */
final class SchoolYearClock {

	/**
	 * Constructor.
	 *
	 * @param SchoolYear   $schoolYear  The school year as the school sets it.
	 * @param ITimeFactory $timeFactory The server's clock.
	 */
	public function __construct(
		public readonly SchoolYear $schoolYear,
		public readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()
}//end class
