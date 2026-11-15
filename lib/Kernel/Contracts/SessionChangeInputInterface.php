<?php

/**
 * Contract of SessionChangeInput
 *
 * The public surface of SessionChangeInput that other components call, so they depend
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
 * Contract of SessionChangeInput.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SessionChangeInputInterface {

	/**
	 * Check and normalise the input.
	 *
	 * @param array<string, mixed> $input The request body.
	 *
	 * @return array{kind: string, sessionIds: array<int, string>, changeReasonKind: string,
	 *     changeReason: ?string, substituteTeacherId: ?string, roomId: ?string}
	 *
	 * @throws InvalidArgumentException When something required is missing.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-every-lesson-in-a-batch-passes-the-same-checks
	 */
	public function validated(array $input): array;
}//end interface
