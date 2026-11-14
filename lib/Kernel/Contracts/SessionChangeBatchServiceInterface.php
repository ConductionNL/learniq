<?php

/**
 * Contract of SessionChangeBatchService
 *
 * The public surface of SessionChangeBatchService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of SessionChangeBatchService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SessionChangeBatchServiceInterface {

	/**
	 * Apply one change to every listed lesson and store the batch.
	 *
	 * @param array<string, mixed> $input    `kind`, `sessionIds`, `changeReasonKind`, and `changeReason`, `substituteTeacherId` or `roomId`.
	 * @param string               $callerId The person applying the change.
	 *
	 * @return array<string, mixed> The stored batch, with `results` per lesson.
	 *
	 * @throws InvalidArgumentException When the input is incomplete.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-every-lesson-in-a-batch-passes-the-same-checks
	 */
	public function apply(array $input, string $callerId): array;
}//end interface
