<?php

/**
 * Contract of LearningRecordExportService
 *
 * The public surface of LearningRecordExportService that other components call, so they depend
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
 * Contract of LearningRecordExportService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LearningRecordExportServiceInterface {

	/**
	 * Assemble, sign and store the export bundle.
	 *
	 * @param array<string,mixed> $export The LearningRecordExport data array.
	 *
	 * @return array<string,mixed> The export with coverageReport, bundleRef, bundleSignature,
	 *                             issuerDid and generatedAt set and errorMessage cleared.
	 *
	 * @throws RuntimeException When the bundle can not be composed, signed or stored.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-generation-fails-closed-and-blocks-the-transition-on-error
	 */
	public function generate(array $export): array;
}//end interface
