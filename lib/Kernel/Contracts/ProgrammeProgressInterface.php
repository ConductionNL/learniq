<?php

/**
 * Contract of ProgrammeProgress
 *
 * The public surface of ProgrammeProgress that other components call, so they depend
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
 * Contract of ProgrammeProgress.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProgrammeProgressInterface {

	/**
	 * Progress for every programme the learner has a part enrolment in.
	 *
	 * The reads skip OpenRegister's RBAC because they are scoped here: only
	 * rows whose `learnerId` is the caller, and the programmes and courses
	 * those rows name.
	 *
	 * @param string $userId The learner's Nextcloud user id.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#scenario-optional-parts-do-not-block-completion
	 */
	public function forLearner(string $userId): array;
}//end interface
