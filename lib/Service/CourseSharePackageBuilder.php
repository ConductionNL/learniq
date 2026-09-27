<?php

/**
 * Learniq Course Share Package Builder
 *
 * Turns the scholiq-native export payload into a package that may leave the
 * school: it drops every field that is the school's own (tenant, envelope,
 * file paths, session, cohort, curriculum-plan, programme and grade-scale
 * references, LTI tool deployments) or secret (an assessment's access code),
 * and adds a `sharing` block with the NL-LOM metadata a receiving school and
 * the store plane show. Pure: no I/O.
 *
 * The package names the chosen `author`, never the Nextcloud user who
 * confirmed the share; that id stays in the school's CourseShareConsent.
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
 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-share-package-carries-no-school-bound-or-personal-fields
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Strips a course payload for sharing and adds its sharing metadata.
 */
class CourseSharePackageBuilder {

	/**
	 * Fields removed from every exported object. When a schema that the
	 * export reads gains a field naming a person, a school structure or a
	 * secret, add it here; CourseSharePackageBuilderTest pins the list.
	 */
	public const STRIP_KEYS = [
		'@self',
		'tenant_id',
		'fileRef',
		'sessionId',
		'cohortId',
		'curriculumPlanId',
		'curriculumPlanComponentId',
		'programmeIds',
		'gradeEntryComponentId',
		'gradeScaleId',
		'accessCode',
		'openconnectorDeploymentId',
	];

	/**
	 * The payload lists whose objects are stripped.
	 */
	private const OBJECT_LISTS = ['childCourses', 'lessons', 'materials', 'assessments', 'rubrics'];

	/**
	 * At most this many goals are listed in the sharing block.
	 */
	private const MAX_GOALS = 50;

	/**
	 * Strip the payload and add the sharing block.
	 *
	 * @param array<string, mixed> $payload  The scholiq-native payload.
	 * @param string               $sharedAt ISO 8601 moment of sharing.
	 *
	 * @return array<string, mixed> The share package.
	 *
	 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-share-package-carries-no-school-bound-or-personal-fields
	 */
	public function build(array $payload, string $sharedAt): array {
		$package = $this->strip(payload: $payload);
		$package['sharing'] = $this->sharingBlock(
			course: (array)($payload['course'] ?? []),
			lessons: (array)($payload['lessons'] ?? []),
			sharedAt: $sharedAt
		);

		return $package;

	}//end build()

	/**
	 * Remove school-bound, personal and secret fields; drop LTI placements.
	 *
	 * @param array<string, mixed> $payload The scholiq-native payload.
	 *
	 * @return array<string, mixed> The stripped payload.
	 *
	 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-share-package-carries-no-school-bound-or-personal-fields
	 */
	public function strip(array $payload): array {
		$payload['course'] = $this->stripObject(object: (array)($payload['course'] ?? []));

		foreach (self::OBJECT_LISTS as $list) {
			$payload[$list] = array_map(
				fn (mixed $object): array => $this->stripObject(object: (array)$object),
				array_values((array)($payload[$list] ?? []))
			);
		}

		// LTI placements are the school's own tool deployments.
		$payload['ltiPlacements'] = [];

		return $payload;

	}//end strip()

	/**
	 * The NL-LOM metadata a receiving school and the store card show.
	 *
	 * @param array<string, mixed>             $course   The course object.
	 * @param array<int, array<string, mixed>> $lessons  Its lessons.
	 * @param string                           $sharedAt ISO 8601 moment of sharing.
	 *
	 * @return array<string, mixed> The sharing block.
	 *
	 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-share-package-carries-no-school-bound-or-personal-fields
	 */
	public function sharingBlock(array $course, array $lessons, string $sharedAt): array {
		$levels = $this->strings(value: ($course['educationalLevels'] ?? []));
		$goals  = [];
		$subject = $this->text(value: ($course['subject'] ?? null));

		foreach ($lessons as $lesson) {
			$levels = [...$levels, ...$this->strings(value: ($lesson['educationalLevels'] ?? []))];
			$goals  = [...$goals, ...$this->strings(value: ($lesson['learningObjectives'] ?? []))];
			if ($subject === '') {
				$subject = $this->text(value: ($lesson['subject'] ?? null));
			}
		}

		return [
			'title'             => $this->text(value: ($course['name'] ?? null)),
			'description'       => $this->text(value: ($course['description'] ?? null)),
			'license'           => $this->text(value: ($course['license'] ?? null)),
			'author'            => $this->text(value: ($course['author'] ?? null)),
			'subject'           => $subject,
			'educationalLevels' => array_values(array_unique($levels)),
			'language'          => $this->text(value: ($course['language'] ?? null)),
			'goalsCovered'      => array_slice(array_values(array_unique($goals)), 0, self::MAX_GOALS),
			'lessonCount'       => count($lessons),
			'sharedAt'          => $sharedAt,
		];

	}//end sharingBlock()

	/**
	 * Remove the strip keys from one object.
	 *
	 * @param array<string, mixed> $object The exported object.
	 *
	 * @return array<string, mixed>
	 */
	private function stripObject(array $object): array {
		return array_diff_key($object, array_flip(self::STRIP_KEYS));

	}//end stripObject()

	/**
	 * The non-empty trimmed strings in a list.
	 *
	 * @param mixed $value The raw list.
	 *
	 * @return array<int, string>
	 */
	private function strings(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $item) {
			$text = $this->text(value: $item);
			if ($text !== '') {
				$out[] = $text;
			}
		}

		return $out;

	}//end strings()

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
