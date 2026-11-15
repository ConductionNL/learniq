<?php

/**
 * Learniq Rollover Interrupted Exception
 *
 * Thrown when a rollover run stops part-way, carrying the per-mapping progress
 * of the mappings finished before the failure.
 *
 * @category Exception
 * @package  OCA\Learniq\Exception
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/school-year-rollover/spec.md#requirement-execution-must-be-audited-idempotent-per-mapping-and-resumable
 */

declare(strict_types=1);

namespace OCA\Learniq\Exception;

use RuntimeException;
use Throwable;

/**
 * A rollover run failed after finishing some of its mappings.
 *
 * @spec openspec/specs/school-year-rollover/spec.md#requirement-execution-must-be-audited-idempotent-per-mapping-and-resumable
 */
class RolloverInterruptedException extends RuntimeException {

	/**
	 * Constructor; the message is the cause's message.
	 *
	 * @param array<string,string> $progress Map of fromCohortId => 'done' for the finished mappings.
	 * @param Throwable            $cause    What stopped the run.
	 */
	public function __construct(
		private readonly array $progress,
		Throwable $cause,
	) {
		parent::__construct(message: $cause->getMessage(), previous: $cause);
	}//end __construct()

	/**
	 * The per-mapping progress at the moment the run stopped.
	 *
	 * @return array<string,string> Map of fromCohortId => 'done'.
	 *
	 * @spec openspec/specs/school-year-rollover/spec.md#requirement-execution-must-be-audited-idempotent-per-mapping-and-resumable
	 */
	public function getProgress(): array {
		return $this->progress;
	}//end getProgress()
}//end class
