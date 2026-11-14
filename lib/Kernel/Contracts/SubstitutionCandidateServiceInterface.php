<?php

/**
 * Contract of SubstitutionCandidateService
 *
 * The public surface of SubstitutionCandidateService that other components call, so they depend
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
 * Contract of SubstitutionCandidateService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubstitutionCandidateServiceInterface {

	/**
	 * The candidates for a lesson.
	 *
	 * @param array<string,mixed> $session The lesson (a learniq Session).
	 * @param array<string,mixed> $cohort  Its cohort.
	 *
	 * @return array<int,array<string,mixed>> `userId`, `displayName`, `group`, `reason` (and `slot` for standby); standby, then free, then busy standby.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
	 */
	public function forSession(array $session, array $cohort): array;
}//end interface
