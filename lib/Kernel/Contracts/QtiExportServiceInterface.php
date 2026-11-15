<?php

/**
 * Contract of QtiExportService
 *
 * The public surface of QtiExportService that other components call, so they depend
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
 * Contract of QtiExportService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface QtiExportServiceInterface {

	/**
	 * Export an ItemBank's Items as a QTI 2.1 package ZIP.
	 *
	 * @param string $itemBankId UUID of the ItemBank to export.
	 *
	 * @return string Raw ZIP bytes.
	 *
	 * @throws \RuntimeException When the ItemBank does not exist.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/specs/assessment/spec.md#scenario-exporting-an-itembank-produces-a-valid-qti-30-package
	 * @spec openspec/specs/assessment/spec.md#requirement-itembank-exports-its-items-as-a-qti-21-package
	 */
	public function export(string $itemBankId): string;
}//end interface
