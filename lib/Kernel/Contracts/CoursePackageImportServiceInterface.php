<?php

/**
 * Contract of CoursePackageImportService
 *
 * The public surface of CoursePackageImportService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

/**
 * Contract of CoursePackageImportService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CoursePackageImportServiceInterface {

	/**
	 * Import a course package and persist its `CoursePackageImportReport`.
	 *
	 * @param string $packagePath Absolute path to the uploaded archive (tmp upload path).
	 * @param string $sourceFilename Original filename as supplied by the browser.
	 * @param string $importedBy NC user id of the caller.
	 * @param string $tenantId Tenant UUID stamped on every created object.
	 *
	 * @return array<string, mixed> The persisted `CoursePackageImportReport` (includes `uuid`).
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
	 */
	public function import(string $packagePath, string $sourceFilename, string $importedBy, string $tenantId = ''): array;
}//end interface
