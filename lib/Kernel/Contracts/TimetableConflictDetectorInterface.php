<?php

/**
 * Contract of TimetableConflictDetector
 *
 * The public surface of TimetableConflictDetector that other components call, so they depend
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
 * Contract of TimetableConflictDetector.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimetableConflictDetectorInterface {

	/**
	 * Scan the affected window around the given Session ids for conflicts.
	 *
	 * @param array<int,array<string,mixed>> $sessions The freshly changed/imported Session objects (already-fetched data arrays).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-two-sessions-imported-for-the-same-room-at-overlapping-times-are-flagged-not-auto-moved
	 * @spec openspec/specs/timetabling/spec.md#scenario-re-scanning-an-unchanged-window-does-not-create-duplicate-conflicts
	 * @spec openspec/specs/timetabling/spec.md#scenario-an-exam-session-exceeding-room-capacity-is-flagged-as-room-capacity-exceeded
	 */
	public function scan(array $sessions): void;
}//end interface
