<?php

/**
 * Learniq Programme Requirements
 *
 * Which parts of a programme are mandatory by default. The author lists them
 * in `Programme.mandatoryCourseIds`; every other course of the programme is
 * optional. A programme without the list marks nothing mandatory, which is
 * how every programme behaved before (design D2). The default is copied onto
 * each course enrolment when a person is enrolled in the programme, and the
 * enrolment's own `mandatory` flag is the truth from then on (design D1), so
 * a manager can change it for one person.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Programme
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
 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#requirement-mandatory-and-optional-parts-of-a-programme
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Programme;

/**
 * The default `mandatory` flag of one part of a programme.
 */
class ProgrammeRequirements {

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
	public function mandatoryFor(array $programme, string $courseId): bool {
		$parts = array_map('strval', (array)($programme['courseIds'] ?? []));
		$mandatory = array_map('strval', (array)($programme['mandatoryCourseIds'] ?? []));

		return in_array($courseId, $parts, true) === true && in_array($courseId, $mandatory, true) === true;
	}//end mandatoryFor()
}//end class
