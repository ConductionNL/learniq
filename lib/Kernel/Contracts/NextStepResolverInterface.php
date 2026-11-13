<?php

/**
 * Contract of NextStepResolver
 *
 * The public surface of NextStepResolver that other components call, so they depend
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
 * Contract of NextStepResolver.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NextStepResolverInterface {

	/**
	 * The next lesson for this learner after the given lesson, from the
	 * learner's own results.
	 *
	 * @param array<string, mixed> $lesson    The Lesson row.
	 * @param string               $learnerId The learner whose results count (the caller).
	 *
	 * @return array{nextLessonId: string|null, rule: int|null}
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-learner-who-fails-is-sent-to-a-refresher
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-learner-who-passes-continues
	 */
	public function resolve(array $lesson, string $learnerId): array;

	/**
	 * The next lesson in a preview: every score rule sees the simulated
	 * score, every completion rule holds, and nobody's results are read.
	 *
	 * @param array<string, mixed> $lesson         The Lesson row.
	 * @param float|null           $simulatedScore The score the author chose, or null for none.
	 *
	 * @return array{nextLessonId: string|null, rule: int|null}
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-teacher-walks-the-course-as-a-learner
	 */
	public function resolvePreview(array $lesson, ?float $simulatedScore): array;
}//end interface
