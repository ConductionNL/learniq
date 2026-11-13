<?php

/**
 * Contract of AssessmentScoringHandler
 *
 * The public surface of AssessmentScoringHandler that other components call, so they depend
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
 * Contract of AssessmentScoringHandler.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AssessmentScoringHandlerInterface {

	/**
	 * Auto-score every response of an AssessmentResult.
	 *
	 * Returns the result with `autoScore` set on each response that names an
	 * item. Items requiring manual scoring get autoScore null.
	 *
	 * @param array<string,mixed> $result The AssessmentResult data array.
	 *
	 * @return array<string,mixed>|null The scored result, the result unchanged when there is
	 *                                  nothing to score, or null (fail-closed) when the parent
	 *                                  Assessment cannot be resolved.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
	 */
	public function score(array $result): ?array;
}//end interface
