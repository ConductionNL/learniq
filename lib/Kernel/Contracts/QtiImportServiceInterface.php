<?php

/**
 * Contract of QtiImportService
 *
 * The public surface of QtiImportService that other components call, so they depend
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
 * Contract of QtiImportService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface QtiImportServiceInterface {

	/**
	 * Import items from a QTI 2.x / 3.0 or Common Cartridge ZIP package.
	 *
	 * Extracts the archive to a temporary directory, detects the package type
	 * from the manifest, parses each item XML, and creates `Item` objects in OR.
	 *
	 * @param string $packagePath Absolute path to the .zip package file.
	 * @param string $itemBankId UUID of the target ItemBank.
	 * @param string $tenantId Optional tenant UUID; defaults to single-tenant mode when empty.
	 *
	 * @return string[] Array of created Item UUIDs.
	 *
	 * @throws \RuntimeException When the archive cannot be opened or is not a recognised format.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
	 */
	public function import(string $packagePath, string $itemBankId, string $tenantId = ''): array;
}//end interface
