<?php

/**
 * Learniq Course Store Registry Object
 *
 * Turns a share package (the output of the sharing gate, change
 * lesson-sharing-consent-gate) into the `shared-course-package` object a
 * registry stores. Pure: no I/O.
 *
 * The store plane casts each card property to a string, so every card field is
 * written as a string here (`level` joins the levels, `goals` joins the goals);
 * the arrays travel alongside for filtering. The full package rides as
 * `package`, which is what an install imports.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CourseStore
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
 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-any-learniq-instance-can-act-as-the-registry
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseStore;

/**
 * Share package in, registry object out.
 */
class CourseStoreRegistryObject {

	public const SLUG_PREFIX = 'course-package-';

	/**
	 * Longest title part of a slug.
	 */
	private const SLUG_TITLE_LENGTH = 40;

	/**
	 * Longest joined goals string on a card.
	 */
	private const GOALS_LENGTH = 500;

	/**
	 * Build the registry object for a share package.
	 *
	 * @param array<string, mixed> $package The share package (with its `sharing` block).
	 *
	 * @return array<string, mixed> The `shared-course-package` object.
	 *
	 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-any-learniq-instance-can-act-as-the-registry
	 */
	public function build(array $package): array {
		$sharing = (array)($package['sharing'] ?? []);
		$levels  = $this->strings(value: ($sharing['educationalLevels'] ?? []));
		$goals   = $this->strings(value: ($sharing['goalsCovered'] ?? []));
		$subject = $this->text(value: ($sharing['subject'] ?? null));
		$license = $this->text(value: ($sharing['license'] ?? null));

		$line = array_values(array_filter([$subject, implode(', ', $levels), $license], static fn (string $part): bool => $part !== ''));

		return [
			'slug'         => $this->slug(package: $package),
			'kind'         => CourseStoreDescriptor::KIND,
			'title'        => $this->text(value: ($sharing['title'] ?? null)),
			'description'  => $this->text(value: ($sharing['description'] ?? null)),
			'subject'      => $subject,
			'level'        => implode(', ', $levels),
			'levels'       => $levels,
			'goals'        => mb_substr(implode('; ', $goals), 0, self::GOALS_LENGTH),
			'goalsCovered' => $goals,
			'language'     => $this->text(value: ($sharing['language'] ?? null)),
			'license'      => $license,
			'author'       => $this->text(value: ($sharing['author'] ?? null)),
			'cardLine'     => implode(' · ', $line),
			'version'      => '1',
			'lessonCount'  => (int)($sharing['lessonCount'] ?? 0),
			'sharedAt'     => $this->text(value: ($sharing['sharedAt'] ?? null)),
			'package'      => $package,
		];

	}//end build()

	/**
	 * A readable slug that differs per content:
	 * `course-package-<kebab title>-<first 8 of sha1(package)>`.
	 *
	 * @param array<string, mixed> $package The share package.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry
	 */
	public function slug(array $package): string {
		$title = $this->text(value: (((array)($package['sharing'] ?? []))['title'] ?? null));
		$kebab = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '-', $this->ascii(text: $title)));
		$kebab = trim(substr(trim($kebab, '-'), 0, self::SLUG_TITLE_LENGTH), '-');
		if ($kebab === '') {
			$kebab = 'course';
		}

		$hash = substr(sha1((string)json_encode($package)), 0, 8);

		return self::SLUG_PREFIX . $kebab . '-' . $hash;

	}//end slug()

	/**
	 * Transliterate accents so "Aardrijkskunde, één" becomes "Aardrijkskunde, een".
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function ascii(string $text): string {
		$converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
		if ($converted === false) {
			return $text;
		}

		return $converted;

	}//end ascii()

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

		return array_values(array_unique($out));

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
