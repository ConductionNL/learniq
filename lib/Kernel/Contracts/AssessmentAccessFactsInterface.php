<?php

/**
 * Contract of AssessmentAccessFacts
 *
 * The public surface of AssessmentAccessFacts that other components call, so they depend
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
 * Contract of AssessmentAccessFacts.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AssessmentAccessFactsInterface {

	/**
	 * The attempt-gate facts TakeAssessmentView needs before it starts an
	 * attempt: why the window is shut (a code it can translate) and whether an
	 * access code is needed. The code itself is write-only and is never
	 * returned; the Assessment is read raw here only to report that one exists.
	 *
	 * @param string $assessmentId UUID of the Assessment.
	 * @param array<string, mixed> $item The rendered Assessment row.
	 *
	 * @return array{reasonCode: string|null, requiresAccessCode: bool}
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
	 */
	public function assessmentAccess(string $assessmentId, array $item): array;
}//end interface
