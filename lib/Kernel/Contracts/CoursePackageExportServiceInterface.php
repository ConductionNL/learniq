<?php

/**
 * Contract of CoursePackageExportService
 *
 * The public surface of CoursePackageExportService that other components call, so they depend
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
 * Contract of CoursePackageExportService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CoursePackageExportServiceInterface {

	/**
	 * Export a Course as an IMS Common Cartridge 1.3 package ZIP.
	 *
	 * @param string $courseId UUID of the Course to export.
	 * @param string $exportingUser NC user id whose home folder resolves `Material.fileRef` bytes.
	 *
	 * @return string Raw ZIP bytes.
	 *
	 * @throws \RuntimeException When the Course does not exist.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-portable-common-cartridge-package
	 */
	public function exportCommonCartridge(string $courseId, string $exportingUser): string;

	/**
	 * Export a Course as a lossless scholiq-native JSON tree.
	 *
	 * @param string $courseId UUID of the Course to export.
	 * @param string $exportingUser NC user id whose home folder resolves `Material.fileRef` bytes.
	 *
	 * @return string JSON document.
	 *
	 * @throws \RuntimeException When the Course does not exist.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
	 */
	public function exportScholiqJson(string $courseId, string $exportingUser): string;
}//end interface
