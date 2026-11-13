<?php

/**
 * Contract of LearningRecordImportService
 *
 * The public surface of LearningRecordImportService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of LearningRecordImportService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LearningRecordImportServiceInterface {

	/**
	 * Parse the uploaded bundle into an evidence-only coverage report.
	 *
	 * @param array<string,mixed> $import The LearningRecordImport data array.
	 *
	 * @return array<string,mixed> The import with entries, issuerDid and verificationStatus
	 *                             set and errorMessage cleared.
	 *
	 * @throws RuntimeException When the bundle can not be read, decoded or recognised.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-coordinator-uploads-a-prior-scholiq-export-during-intake-and-sees-a-verified-coverage-report
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-an-unrecognisable-file-fails-closed-without-partial-data
	 */
	public function parse(array $import): array;
}//end interface
