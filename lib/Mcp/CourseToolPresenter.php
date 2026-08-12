<?php

/**
 * Scholiq MCP Course Tool Presenter
 *
 * Response-shaping half of the Scholiq MCP surface, extracted from
 * `ScholiqToolProvider` so each class carries one cohesive responsibility: this
 * one owns *how a course or module is rendered to the LLM* (normalising an
 * OpenRegister object to a plain array, extracting its UUID, allow-listing the
 * privacy-safe fields and building the citation sources plus their deep links),
 * while `ScholiqToolProvider` keeps the tool catalogue, argument validation,
 * authorisation and the reads.
 *
 * The allow-lists live here and nowhere else: every field an LLM can ever see
 * for a Course or a Lesson is enumerated in `courseSummary()`/`moduleSummary()`,
 * so the "no learner PII leaves Scholiq" guarantee is reviewable in one place.
 *
 * @category Mcp
 * @package  OCA\Scholiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Scholiq\Mcp;

/**
 * Normalises OpenRegister course/lesson objects into privacy-safe MCP payloads.
 */
class CourseToolPresenter {
	/**
	 * Build a citation source descriptor for a course object.
	 *
	 * @param array<string, mixed> $course The normalised course array.
	 * @param string $courseUuid The course UUID.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-3
	 */
	public function courseSource(array $course, string $courseUuid): array {
		return [
			'type' => 'scholiq.course',
			'uuid' => $courseUuid,
			'url' => $this->buildDeepLink(type: 'course', uuid: $courseUuid),
			'label' => (string)($course['name'] ?? $course['code'] ?? 'course'),
		];

	}//end courseSource()

	/**
	 * Build the citation-source list for a course-details response: the course
	 * itself followed by one entry per module, each with its own deep link.
	 *
	 * @param array<string, mixed> $course The course record.
	 * @param string $courseUuid The course UUID.
	 * @param array<int, array<string, mixed>> $modules Ordered module summaries.
	 *
	 * @return array<int, array<string, mixed>> Source entries in course-then-modules order.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-4
	 */
	public function buildCourseDetailSources(array $course, string $courseUuid, array $modules): array {
		$sources = [$this->courseSource(course: $course, courseUuid: $courseUuid)];

		foreach ($modules as $module) {
			$moduleUuid = (string)($module['uuid'] ?? '');
			$sources[] = [
				'type' => 'scholiq.module',
				'uuid' => $moduleUuid,
				'url' => $this->buildDeepLink(type: 'module', uuid: $moduleUuid),
				'label' => (string)($module['name'] ?? 'Module'),
			];
		}

		return $sources;
	}//end buildCourseDetailSources()

	/**
	 * Build a privacy-safe summary of a course object.
	 *
	 * Only catalogue-level fields are included. No learner-related fields are
	 * present on the Course schema, but we still allow-list explicitly.
	 *
	 * @param array<string, mixed> $course The normalised course array.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-3
	 */
	public function courseSummary(array $course): array {
		return [
			'uuid' => $this->extractUuid(item: $course),
			'code' => $course['code'] ?? null,
			'name' => $course['name'] ?? null,
			'name_nl' => $course['name_nl'] ?? null,
			'description' => $course['description'] ?? null,
			'level' => $course['level'] ?? null,
			'language' => $course['language'] ?? null,
			'tags' => $course['tags'] ?? [],
			'mandatoryTraining' => $course['mandatoryTraining'] ?? false,
			'regulationSlug' => $course['regulationSlug'] ?? null,
			'renewalCourseSlug' => $course['renewalCourseSlug'] ?? null,
			'lifecycle' => $course['lifecycle'] ?? null,
		];

	}//end courseSummary()

	/**
	 * Build a privacy-safe summary of a Lesson (module) object.
	 *
	 * @param array<string, mixed> $lesson The normalised lesson array.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-4
	 */
	public function moduleSummary(array $lesson): array {
		$order = null;
		if (isset($lesson['order']) === true) {
			$order = (int)$lesson['order'];
		}

		return [
			'uuid' => $this->extractUuid(item: $lesson),
			'name' => $lesson['name'] ?? null,
			'order' => $order,
			'contentType' => $lesson['contentType'] ?? null,
			'durationMinutes' => $lesson['durationMinutes'] ?? null,
			'learningObjectives' => $lesson['learningObjectives'] ?? [],
			'mandatoryTraining' => $lesson['mandatoryTraining'] ?? false,
			'regulationSlug' => $lesson['regulationSlug'] ?? null,
			'lifecycle' => $lesson['lifecycle'] ?? null,
		];

	}//end moduleSummary()

	/**
	 * Build a deep link URL for a Scholiq resource.
	 *
	 * @param string $type One of: course, module.
	 * @param string $uuid The object UUID.
	 *
	 * @return string The deep link path, e.g. /apps/scholiq/courses/<uuid>.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-4
	 */
	public function buildDeepLink(string $type, string $uuid): string {
		$paths = [
			'course' => '/apps/scholiq/courses',
			'module' => '/apps/scholiq/modules',
		];

		$base = $paths[$type] ?? "/apps/scholiq/{$type}s";
		return "{$base}/{$uuid}";
	}//end buildDeepLink()

	/**
	 * Normalise an OpenRegister object to a plain PHP array.
	 *
	 * @param mixed $item Raw item from ObjectService.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-5
	 */
	public function toArray(mixed $item): array {
		if (is_array(value: $item) === true) {
			return $item;
		}

		if (is_object(value: $item) === true) {
			foreach (['getObject', 'jsonSerialize'] as $method) {
				if (method_exists($item, $method) === false) {
					continue;
				}

				$value = $item->$method();
				if (is_array(value: $value) === true) {
					return $value;
				}
			}
		}

		return (array)$item;
	}//end toArray()

	/**
	 * Extract the UUID from a normalised object array.
	 *
	 * Checks multiple common field names to handle different OR object shapes.
	 *
	 * @param array<string, mixed> $item The normalised object array.
	 *
	 * @return string The UUID, or empty string when not found.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-5
	 */
	public function extractUuid(array $item): string {
		$uuid = $item['uuid'] ?? $item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? ''));
		return (string)$uuid;
	}//end extractUuid()
}//end class
