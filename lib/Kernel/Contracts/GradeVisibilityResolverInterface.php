<?php

/**
 * Contract of GradeVisibilityResolver
 *
 * The public surface of GradeVisibilityResolver that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of GradeVisibilityResolver.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GradeVisibilityResolverInterface {

	/**
	 * Resolve the effective `visibleFrom` for a GradeEntry publish.
	 *
	 * @param string|null $override Teacher-supplied ISO-8601 `visibleFrom` override, if any.
	 * @param array<string, mixed>|null $policy CurriculumPlan.gradeVisibilityPolicy (`{mode, time, timezone}` or null).
	 * @param DateTimeImmutable $publishedAt Moment the GradeEntry transitioned to published.
	 *
	 * @return DateTimeImmutable The resolved visibleFrom.
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
	 */
	public function resolve(
		?string $override,
		?array $policy,
		DateTimeImmutable $publishedAt,
	): DateTimeImmutable;
}//end interface
