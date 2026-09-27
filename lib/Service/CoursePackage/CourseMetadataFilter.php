<?php

/**
 * Learniq Course Metadata Filter
 *
 * Picks the course metadata an imported copy keeps from its source: level,
 * language, description, and the sharing metadata licence, author, subject and
 * NL-LOM levels (course-content-metadata). A package comes off the network or
 * out of a file, so only values the Course schema accepts pass: a level or
 * licence outside its enum, NL-LOM levels outside theirs, or a language that
 * is not two lowercase letters are dropped rather than written, which would
 * make OpenRegister refuse the whole course. Pure: no I/O.
 *
 * CourseMetadataFilterTest pins the enums below against the register, so the
 * two cannot drift apart unnoticed.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CoursePackage
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
 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

/**
 * Valid course metadata out of an untrusted course row.
 */
class CourseMetadataFilter {

	/**
	 * `Course.level` values.
	 */
	public const LEVELS = ['po', 'vo', 'mbo', 'hbo', 'wo', 'corporate'];

	/**
	 * `Course.license` values.
	 */
	public const LICENSES = [
		'CC0-1.0',
		'CC-BY-4.0',
		'CC-BY-SA-4.0',
		'CC-BY-NC-4.0',
		'CC-BY-NC-SA-4.0',
		'CC-BY-ND-4.0',
		'CC-BY-NC-ND-4.0',
		'all-rights-reserved',
	];

	/**
	 * `Course.educationalLevels` values.
	 */
	public const EDUCATIONAL_LEVELS = [
		'po',
		'so',
		'vmbo',
		'havo',
		'vwo',
		'mbo-1',
		'mbo-2',
		'mbo-3',
		'mbo-4',
		'hbo',
		'wo',
		'adult-education',
		'professional-training',
	];

	/**
	 * Longest text kept per free-text field.
	 */
	private const TEXT_LIMITS = ['description' => 10000, 'author' => 200, 'subject' => 120];

	/**
	 * The metadata of a source course that a copy may keep.
	 *
	 * @param array<string, mixed> $course The source course row.
	 *
	 * @return array<string, mixed> Only valid values, keyed by Course property.
	 *
	 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
	 */
	public function filter(array $course): array {
		return array_merge(
			$this->enums(course: $course),
			$this->language(course: $course),
			$this->texts(course: $course),
			$this->educationalLevels(course: $course)
		);

	}//end filter()

	/**
	 * `level` and `license`, when inside their enums.
	 *
	 * @param array<string, mixed> $course The source course row.
	 *
	 * @return array<string, string>
	 */
	private function enums(array $course): array {
		$out = [];
		foreach (['level' => self::LEVELS, 'license' => self::LICENSES] as $key => $allowed) {
			$value = ($course[$key] ?? null);
			if (is_string($value) === true && in_array($value, $allowed, true) === true) {
				$out[$key] = $value;
			}
		}

		return $out;

	}//end enums()

	/**
	 * `language`, when two lowercase letters.
	 *
	 * @param array<string, mixed> $course The source course row.
	 *
	 * @return array<string, string>
	 */
	private function language(array $course): array {
		$language = ($course['language'] ?? null);
		if (is_string($language) === true && preg_match('/^[a-z]{2}$/', $language) === 1) {
			return ['language' => $language];
		}

		return [];

	}//end language()

	/**
	 * `description`, `author` and `subject`, trimmed and capped.
	 *
	 * @param array<string, mixed> $course The source course row.
	 *
	 * @return array<string, string>
	 */
	private function texts(array $course): array {
		$out = [];
		foreach (self::TEXT_LIMITS as $key => $limit) {
			$value = ($course[$key] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				$out[$key] = mb_substr(trim($value), 0, $limit);
			}
		}

		return $out;

	}//end texts()

	/**
	 * `educationalLevels`, filtered to the NL-LOM enum.
	 *
	 * @param array<string, mixed> $course The source course row.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function educationalLevels(array $course): array {
		$levels = ($course['educationalLevels'] ?? null);
		if (is_array($levels) === false) {
			return [];
		}

		$valid = array_values(array_unique(array_intersect($levels, self::EDUCATIONAL_LEVELS)));
		if ($valid === []) {
			return [];
		}

		return ['educationalLevels' => $valid];

	}//end educationalLevels()
}//end class
