<?php

/**
 * Contract of ElectiveBoard
 *
 * The public surface of ElectiveBoard that other components call, so they depend
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
 * Contract of ElectiveBoard.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ElectiveBoardInterface {

	/**
	 * The open offers a learner may sign up for, with their lessons.
	 *
	 * @param string            $learnerId The learner.
	 * @param DateTimeImmutable|null $now       Now; the current time when null.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
	 */
	public function forLearner(string $learnerId, ?DateTimeImmutable $now=null): array;

	/**
	 * Per lesson who signed up and which eligible learners did not.
	 *
	 * @param string            $offerId The offer.
	 * @param DateTimeImmutable|null $now     Now; the current time when null.
	 *
	 * @return array<string, mixed>|null The roster, or null for an unknown offer.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window
	 */
	public function roster(string $offerId, ?DateTimeImmutable $now=null): ?array;
}//end interface
