<?php

/**
 * Learniq Course Template Skeleton
 *
 * Turns a course tree into a CourseTemplate's `moduleStructure`: module and
 * lesson names, order, content type and duration, and a placeholder for each
 * rich text block. Live references (materials, assessments, assignments, LTI
 * placements) and the lesson text itself are left out.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Course
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Course;

/**
 * A course tree as template module entries.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */
class CourseTemplateSkeleton {

	/**
	 * The template's module entries for a course tree.
	 *
	 * @param list<array<string, mixed>> $modules     The modules in order, each with its sorted `lessons`.
	 * @param string                     $placeholder The text that replaces each rich text block.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	public function moduleStructure(array $modules, string $placeholder): array {
		$structure = [];
		foreach ($modules as $mIndex => $module) {
			$lessons = [];
			foreach (self::listOf(value: $module['lessons'] ?? []) as $lIndex => $lesson) {
				$lessons[] = [
					'key' => 'lesson-' . ($mIndex + 1) . '-' . ($lIndex + 1),
					'name' => (string)($lesson['name'] ?? ''),
					'order' => $lesson['order'] ?? ($lIndex + 1),
					'contentType' => $lesson['contentType'] ?? null,
					'durationMinutes' => $lesson['durationMinutes'] ?? null,
					'blocksSkeleton' => $this->blocks(blocks: $lesson['blocks'] ?? [], placeholder: $placeholder),
				];
			}

			$structure[] = [
				'key' => 'module-' . ($mIndex + 1),
				'name' => (string)($module['name'] ?? ''),
				'order' => $module['order'] ?? ($mIndex + 1),
				'ectsCredits' => $module['ectsCredits'] ?? null,
				'lessons' => $lessons,
			];
		}

		return $structure;
	}//end moduleStructure()

	/**
	 * A placeholder for each rich text block; other blocks are left out.
	 *
	 * @param mixed  $blocks      The lesson's blocks.
	 * @param string $placeholder The placeholder text.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function blocks(mixed $blocks, string $placeholder): array {
		$skeleton = [];
		foreach (self::listOf(value: $blocks) as $block) {
			if (($block['type'] ?? '') !== 'richText') {
				continue;
			}

			$skeleton[] = [
				'blockId' => $block['blockId'] ?? null,
				'type' => 'richText',
				'order' => $block['order'] ?? null,
				'text' => $placeholder,
			];
		}

		return $skeleton;
	}//end blocks()

	/**
	 * The array entries of a list value; anything else is an empty list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, 'is_array'));
	}//end listOf()
}//end class
