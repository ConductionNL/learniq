<?php

/**
 * Contract of CourseTemplateService
 *
 * The public surface of CourseTemplateService that other components call, so they depend
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

use OCA\Learniq\Exception\CourseTemplateRefusedException;

/**
 * Contract of CourseTemplateService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CourseTemplateServiceInterface {

	/**
	 * Create a new, independent course tree from a CourseTemplate: a draft
	 * course, one module per module entry, one lesson per lesson entry, and a
	 * curriculum plan when the template carries one. When any write fails,
	 * everything this call created is removed again.
	 *
	 * @param string $templateId The template uuid.
	 * @param string $name       The new course name.
	 * @param string $language   The new course language; empty for the default.
	 *
	 * @return array{id: string}
	 *
	 * @throws CourseTemplateRefusedException When the name is empty, the template is missing, or a write is refused.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	public function instantiate(string $templateId, string $name, string $language): array;

	/**
	 * Capture a course tree as a new CourseTemplate: module and lesson names,
	 * order and content type, and a placeholder for each rich text block. No
	 * live references and no learner data are copied, and the course is left
	 * unchanged.
	 *
	 * @param string $courseId    The source course uuid.
	 * @param string $name        The template name.
	 * @param string $description The template description; empty for none.
	 *
	 * @return array{id: string}
	 *
	 * @throws CourseTemplateRefusedException When the name is empty, the course is missing, or the user may not create templates.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	public function saveAsTemplate(string $courseId, string $name, string $description): array;

	/**
	 * A course with its modules and each module's lessons, both sorted by
	 * `order` with a missing order last. Lessons for every module come from
	 * one filtered query.
	 *
	 * @param string $courseId The course uuid.
	 *
	 * @return array{course: array<string, mixed>, modules: list<array<string, mixed>>}
	 *
	 * @throws CourseTemplateRefusedException When the course does not exist or the user may not read it.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
	 */
	public function structure(string $courseId): array;
}//end interface
