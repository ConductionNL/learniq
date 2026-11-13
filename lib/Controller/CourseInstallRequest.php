<?php

/**
 * Learniq course store install request.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

/**
 * Which shared course to install, and for whom.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
 */
final class CourseInstallRequest {

	/**
	 * Constructor.
	 *
	 * @param string $slug   The shared course's slug.
	 * @param string $userId The installing user's id.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
