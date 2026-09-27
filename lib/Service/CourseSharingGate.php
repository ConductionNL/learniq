<?php

/**
 * Learniq Course Sharing Gate
 *
 * Decides whether a course may leave the school: the "may this leave the
 * school" check that runs before a share export (lesson-sharing-consent-gate)
 * and before a store publish (lesson-sharing-via-store-plane). Pure: it reads
 * the arrays it is given and returns the reasons for refusal, so every rule is
 * unit-tested in isolation and both callers apply the same rules.
 *
 * The Auteurswet education exception covers use in class, not redistribution
 * (recon E section 3), and pupil data hides in free text and files no scan can
 * judge. So next to the licence rules the gate requires two explicit
 * confirmations from the person who knows the material.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Returns the reasons a course may not leave the school, or none.
 */
class CourseSharingGate {

	/**
	 * Licences under which a course may be shared between schools: CC0 and the
	 * Creative Commons 4.0 family. NC and ND variants allow non-commercial
	 * sharing without changes, which is what sharing between schools is.
	 */
	public const OPEN_LICENSES = [
		'CC0-1.0',
		'CC-BY-4.0',
		'CC-BY-SA-4.0',
		'CC-BY-NC-4.0',
		'CC-BY-NC-SA-4.0',
		'CC-BY-ND-4.0',
		'CC-BY-NC-ND-4.0',
	];

	public const LICENCE_MISSING = 'licence-missing';
	public const LICENCE_NOT_OPEN = 'licence-not-open';
	public const AUTHOR_MISSING = 'author-missing';
	public const LESSON_LICENCE_NOT_OPEN = 'lesson-licence-not-open';
	public const MATERIAL_LICENCE_NOT_OPEN = 'material-licence-not-open';
	public const PUPIL_DATA_NOT_CONFIRMED = 'pupil-data-not-confirmed';
	public const RIGHTS_NOT_CONFIRMED = 'rights-not-confirmed';

	/**
	 * Check a course, its lessons and materials, and the two confirmations.
	 *
	 * @param array<string, mixed>             $course        The course object.
	 * @param array<int, array<string, mixed>> $lessons       Its lessons.
	 * @param array<int, array<string, mixed>> $materials     Its materials.
	 * @param bool                             $noPupilData   The user confirmed the package holds no pupil data.
	 * @param bool                             $rightsCleared The user confirmed the school may share everything in it.
	 *
	 * @return array<int, array{code: string, id: string, name: string}> One entry per reason; empty when the course may leave.
	 *
	 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate
	 */
	public function check(array $course, array $lessons, array $materials, bool $noPupilData, bool $rightsCleared): array {
		$blockers = $this->checkCourse(course: $course);

		foreach ($lessons as $lesson) {
			$own = $this->text(value: ($lesson['license'] ?? null));
			if ($own !== '' && self::isOpen(license: $own) === false) {
				$blockers[] = $this->blocker(code: self::LESSON_LICENCE_NOT_OPEN, row: $lesson, nameKey: 'name');
			}
		}

		foreach ($materials as $material) {
			$own = $this->text(value: ($material['license'] ?? null));
			if ($own !== '' && self::isOpen(license: $own) === false) {
				$blockers[] = $this->blocker(code: self::MATERIAL_LICENCE_NOT_OPEN, row: $material, nameKey: 'title');
			}
		}

		if ($noPupilData === false) {
			$blockers[] = $this->blocker(code: self::PUPIL_DATA_NOT_CONFIRMED, row: $course, nameKey: 'name');
		}

		if ($rightsCleared === false) {
			$blockers[] = $this->blocker(code: self::RIGHTS_NOT_CONFIRMED, row: $course, nameKey: 'name');
		}

		return $blockers;

	}//end check()

	/**
	 * The licence that applies to a lesson: its own, or else its course's.
	 *
	 * @param array<string, mixed> $lesson The lesson object.
	 * @param array<string, mixed> $course The course object.
	 *
	 * @return string The licence code, or '' when neither sets one.
	 *
	 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate
	 */
	public function lessonLicense(array $lesson, array $course): string {
		$own = $this->text(value: ($lesson['license'] ?? null));
		if ($own !== '') {
			return $own;
		}

		return $this->text(value: ($course['license'] ?? null));

	}//end lessonLicense()

	/**
	 * Whether a licence code allows sharing between schools. Compared
	 * case-insensitively, since a material's licence is free text.
	 *
	 * @param string $license The licence code.
	 *
	 * @return bool True for CC0 and the CC 4.0 family.
	 *
	 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate
	 */
	public static function isOpen(string $license): bool {
		$wanted = strtoupper(trim($license));
		foreach (self::OPEN_LICENSES as $open) {
			if (strtoupper($open) === $wanted) {
				return true;
			}
		}

		return false;

	}//end isOpen()

	/**
	 * The course-level rules: an open licence and an author.
	 *
	 * @param array<string, mixed> $course The course object.
	 *
	 * @return array<int, array{code: string, id: string, name: string}> The course-level reasons.
	 */
	private function checkCourse(array $course): array {
		$blockers = [];
		$license  = $this->text(value: ($course['license'] ?? null));

		if ($license === '') {
			$blockers[] = $this->blocker(code: self::LICENCE_MISSING, row: $course, nameKey: 'name');
		} else if (self::isOpen(license: $license) === false) {
			$blockers[] = $this->blocker(code: self::LICENCE_NOT_OPEN, row: $course, nameKey: 'name');
		}

		if ($this->text(value: ($course['author'] ?? null)) === '') {
			$blockers[] = $this->blocker(code: self::AUTHOR_MISSING, row: $course, nameKey: 'name');
		}

		return $blockers;

	}//end checkCourse()

	/**
	 * Build one blocker entry.
	 *
	 * @param string               $code    The reason code.
	 * @param array<string, mixed> $row     The object the reason is about.
	 * @param string               $nameKey The field that names the object.
	 *
	 * @return array{code: string, id: string, name: string}
	 */
	private function blocker(string $code, array $row, string $nameKey): array {
		return [
			'code' => $code,
			'id'   => $this->text(value: ($row['id'] ?? ($row['uuid'] ?? null))),
			'name' => $this->text(value: ($row[$nameKey] ?? null)),
		];

	}//end blocker()

	/**
	 * A trimmed string, or '' for anything that is not a string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);

	}//end text()
}//end class
