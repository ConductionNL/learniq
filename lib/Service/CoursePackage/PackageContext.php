<?php

/**
 * Learniq course package extraction context.
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
 * The extracted package directory, the user who owns the nc:files copies of
 * its bytes, and the tenant stamped on every object it creates.
 *
 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
 */
final class PackageContext {

	/**
	 * Constructor.
	 *
	 * @param string $dir        Extracted package directory.
	 * @param string $importedBy NC user id (nc:files owner for resolved Material bytes).
	 * @param string $tenantId   Tenant UUID.
	 */
	public function __construct(
		public readonly string $dir,
		public readonly string $importedBy,
		public readonly string $tenantId,
	) {
	}//end __construct()
}//end class
