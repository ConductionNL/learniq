<?php

/**
 * Learniq Point Award Scope
 *
 * The tenant of a transitioned object and its own cohort and course, which a
 * PointRule's scope is matched against.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/specs/engagement/spec.md#requirement-pointaward-creation-is-idempotent-and-immutable
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

/**
 * Where a transitioned object sits: its tenant, cohort and course.
 *
 * @spec openspec/specs/engagement/spec.md#requirement-pointaward-creation-is-idempotent-and-immutable
 */
final class PointAwardScope {

	/**
	 * Constructor.
	 *
	 * @param string      $tenantId Tenant identifier.
	 * @param string|null $cohortId The object's own cohortId, if any.
	 * @param string|null $courseId The object's own courseId, if any.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $tenantId,
		public readonly ?string $cohortId,
		public readonly ?string $courseId,
	) {
	}//end __construct()
}//end class
