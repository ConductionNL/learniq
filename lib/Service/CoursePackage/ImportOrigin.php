<?php

/**
 * Learniq course package import origin.
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
 * @spec openspec/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

/**
 * Who imported which uploaded file, when, and for which tenant: the fields
 * every `CoursePackageImportReport` of one import shares.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
 */
final class ImportOrigin {

	/**
	 * Constructor.
	 *
	 * @param string $sourceFilename Original filename.
	 * @param string $importedBy     NC user id of the caller.
	 * @param string $importedAt     ISO-8601 timestamp of the import.
	 * @param string $tenantId       Tenant UUID.
	 */
	public function __construct(
		public readonly string $sourceFilename,
		public readonly string $importedBy,
		public readonly string $importedAt,
		public readonly string $tenantId,
	) {
	}//end __construct()
}//end class
