<?php

/**
 * Contract of SubmissionMarkReader
 *
 * The public surface of SubmissionMarkReader that other components call, so they depend
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
 * Contract of SubmissionMarkReader.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubmissionMarkReaderInterface {

	/**
	 * The marks the caller may read, with the summary when they may read all.
	 *
	 * @param array<string, mixed> $submission The submission.
	 * @param string               $userId     The caller's Nextcloud user id.
	 * @param bool                 $seesAll    Whether the caller reads every mark at any time.
	 *
	 * @return array{allowed: bool, marks: list<array<string, mixed>>, ownMark: array<string, mixed>|null, complete: bool, summary: array|null}
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-the-second-marker-cannot-peek
	 */
	public function forCaller(array $submission, string $userId, bool $seesAll): array;
}//end interface
