<?php

/**
 * Learniq course package import report outcome.
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
 * What one import produced. A failed import carries an error message; a
 * finished one has none, and its report lifecycle follows from its entries.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
 */
final class ImportReportOutcome {

	/**
	 * Constructor.
	 *
	 * @param string                           $sourceFormat `common-cartridge-1.3`, `moodle-backup` or `scholiq-json`.
	 * @param string|null                      $courseId     Top-level Course UUID, or null.
	 * @param array<int, array<string, mixed>> $entries      Report entries.
	 * @param string|null                      $errorMessage Failure reason; null when the import finished.
	 */
	public function __construct(
		public readonly string $sourceFormat,
		public readonly ?string $courseId,
		public readonly array $entries,
		public readonly ?string $errorMessage,
	) {
	}//end __construct()
}//end class
