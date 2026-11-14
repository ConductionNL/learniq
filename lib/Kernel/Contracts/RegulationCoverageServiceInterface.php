<?php

/**
 * Contract of RegulationCoverageService
 *
 * The public surface of RegulationCoverageService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of RegulationCoverageService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RegulationCoverageServiceInterface {

	/**
	 * Coverage per active regulation: in scope, covered, excused, percent and RAG.
	 *
	 * Walks the same learners and asks the same audience, exemption and
	 * coverage questions as ComplianceRollupService::byDepartment(), so the two
	 * cannot disagree
	 * (compliance-rule-coverage-table design D1). The RAG state uses the
	 * regulation's own thresholds (D2); a rule nobody is in scope for has no
	 * percentage and no state.
	 *
	 * @param string|null            $department Only learners of this department and its teams; null for all.
	 * @param DateTimeImmutable|null $now        Evaluation instant (injectable for tests).
	 *
	 * @return array<int,array<string,mixed>> One row per regulation, by name.
	 *
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
	 */
	public function byRegulation(?string $department=null, ?DateTimeImmutable $now=null): array;
}//end interface
