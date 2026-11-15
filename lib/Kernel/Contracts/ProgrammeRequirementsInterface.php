<?php

/**
 * Contract of ProgrammeRequirements
 *
 * The public surface of ProgrammeRequirements that other components call, so they depend
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
 * Contract of ProgrammeRequirements.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProgrammeRequirementsInterface {

	/**
	 * Whether the course is a mandatory part of the programme by default.
	 *
	 * @param array<string, mixed> $programme The programme row.
	 * @param string               $courseId  The course uuid.
	 *
	 * @return bool False for an optional part, a course outside the programme,
	 *              or a programme that marks nothing mandatory.
	 *
	 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#scenario-an-author-marks-a-part-optional
	 */
	public function mandatoryFor(array $programme, string $courseId): bool;
}//end interface
