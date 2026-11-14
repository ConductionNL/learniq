<?php

/**
 * Contract of ConformanceEvidence
 *
 * The public surface of ConformanceEvidence that other components call, so they depend
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
 * Contract of ConformanceEvidence.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConformanceEvidenceInterface {

	/**
	 * The evidence table of one statement, read from the register.
	 *
	 * @param array<string,mixed> $statement The (published) statement.
	 *
	 * @return array{statement:array<string,mixed>,summary:array<string,int>,criteria:array<int,array<string,mixed>>} The table.
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function forStatement(array $statement): array;

	/**
	 * The published statement the public asks about.
	 *
	 * With an id, that statement if it is published; without one, the most
	 * recently evaluated published statement. A draft or archived statement
	 * is never returned: only what the school has published is public.
	 *
	 * @param string|null $statementId An AccessibilityStatement uuid, or null for the current one.
	 *
	 * @return array<string,mixed>|null The statement, or null when nothing is published.
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function publishedStatement(?string $statementId): ?array;

	/**
	 * The table as CSV: one row per criterion.
	 *
	 * @param array{criteria:array<int,array<string,mixed>>} $evidence The table from build().
	 *
	 * @return string The CSV text, header first.
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function toCsv(array $evidence): string;
}//end interface
