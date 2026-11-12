<?php

/**
 * Learniq Moodle Activity Run
 *
 * The one Moodle activity `MoodleActivityRouter` is routing, the course it
 * lands in and the backup it came from. Built and read only inside the
 * router, for the length of one `routeActivity()` call.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CoursePackage
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
 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

/**
 * One activity being routed: its row, its course and its package.
 */
final class MoodleActivityRun {

	/**
	 * One `MoodleBackupParser` activity row.
	 *
	 * @var array<string, mixed>
	 */
	public array $activity;

	/**
	 * Enclosing Course UUID.
	 *
	 * @var string
	 */
	public string $courseId;

	/**
	 * The extracted backup: its directory, the importing user and the tenant.
	 *
	 * @var PackageContext
	 */
	public PackageContext $package;
}//end class
