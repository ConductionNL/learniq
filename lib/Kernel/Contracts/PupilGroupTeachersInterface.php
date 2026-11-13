<?php

/**
 * Contract of PupilGroupTeachers
 *
 * The public surface of PupilGroupTeachers that other components call, so they depend
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
 * Contract of PupilGroupTeachers.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PupilGroupTeachersInterface {

	/**
	 * The distinct Nextcloud user ids of the teachers of the pupil's current
	 * groups, in the order the groups list them. Empty for an empty id or a
	 * pupil in no current group.
	 *
	 * @param string $learnerId Nextcloud user id of the pupil.
	 *
	 * @return array<int, string>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-an-absence-report-is-read-by-the-teachers-of-the-pupils-group-and-by-school-wide-staff
	 */
	public function forLearner(string $learnerId): array;
}//end interface
